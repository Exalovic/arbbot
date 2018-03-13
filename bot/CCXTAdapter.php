<?php

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/../lib/composer/vendor/autoload.php';

trait CCXTErrorHandler {

  public function __call( $function, $params ) {
    for ( $i = 0; $i < 5; $i++ ) {
      try {
        return call_user_func_array( $this->$function, $params );
      }
      catch ( Exception $ex ) {
        // For buy() and sell() functions, convert exceptions into null return values.
        if ( $function == 'create_order' &&
             ( $ex instanceof \ccxt\ExchangeError ||
               $ex instanceof \ccxt\NetworkError ) ) {
          // Emulate the log messages that the other exchanges show.
          logg( "[" . strtoupper( $this->id ) . "] Got an exception in " . $params[ 2 ] . "(): " . $ex->getMessage() );
          return null;
        } else if ( $ex instanceof \ccxt\NetworkError ) {
          // Retry!
          continue;
        }
        throw $ex;
      }
    }
  }

}

abstract class CCXTAdapter extends Exchange {

  protected $exchange = null;
  private $id = 0;
  private $name = '';

  protected $coinNames = [ ];
  private $tradeFees = [ ];
  private $precisions = [ ];
  private $limits = [ ];

  private $lastStuckReportTime = [ ];
  private $lastDuplicateWithdrawalTime = 0;

  function __construct( $id, $name, $ccxtName, $extraOptions = array( ) ) {
    $this->id = $id;
    $this->name = $name;

    $lower = strtolower( $name );
    $key = Config::get( sprintf( "%s.key", $lower ) );
    $secret = Config::get( sprintf( "%s.secret", $lower ) );
    parent::__construct( $key, $secret );

    $this->exchange = new $ccxtName( array_merge( array(
      'enableRateLimit' => true,
    ), $extraOptions ) );
    $this->exchange->apiKey = $key;
    $this->exchange->secret = $secret;
    $this->exchange->rateLimit = $this->getRateLimit();

  }

  public abstract function checkAPIReturnValue( $result );

  public abstract function isMarketActive( $market );

  public abstract function getRateLimit();

  /**
   ************************************************************
   * Must return an array with the following keys:
   * history: an array of deposit history entries
   * statusKey: key name for the status field
   * coinKey: key name for the coin field
   * amountKey: key name for the amount field
   * pending: the value of status when the deposit is pending. can be an array.
   ************************************************************
   */
  public abstract function getDepositHistory();

  /**
   ************************************************************
   * Must return an array with the following keys:
   * history: an array of withdrawal history entries
   * statusKey: key name for the status field
   * coinKey: key name for the coin field
   * amountKey: key name for the amount field
   * addressKey: key name for the address field
   * txidKey: key name for the txid field
   * pending: the value of status when the withdrawal is pending. can be an array.
   * completed: the value of status when the withdrawal is completed. can be an array.
   ************************************************************
   */
  public abstract function getWithdrawalHistory();

  public function addFeeToPrice( $price, $tradeable, $currency ) {

    $pair = $tradeable . "_" . $currency;
    return $price * (1 + $this->tradeFees[ $pair ]);

  }

  public function deductFeeFromAmountBuy( $amount, $tradeable, $currency ) {

    $pair = $tradeable . "_" . $currency;
    return $amount * (1 - $this->tradeFees[ $pair ]);

  }

  public function deductFeeFromAmountSell( $amount, $tradeable, $currency ) {

    $pair = $tradeable . "_" . $currency;
    return $amount * (1 - $this->tradeFees[ $pair ]);

  }

  public function getTickers( $currency ) {

    $currency = $this->coinNames[ $currency ];
    $markets = $this->exchange->loadMarkets();

    $ticker = [ ];
    foreach ( $markets as $market ) {
      if ( $market[ 'quote' ] != $currency ) {
        continue;
      }

      $ticker[] = $market[ 'symbol' ];
    }

    $tickers = $this->exchange->fetchTickers( $ticker );
    $ticker = [ ];
    foreach ( $tickers as $row ) {
      $split = explode( '/', $row[ 'symbol' ] );

      // The API doesn't provide the last price, so we just take an average into our spread. :-(
      $ticker[ strtoupper( $split[ 0 ] ) ] = formatBTC( ( $row[ 'info' ][ 'bidPrice' ] + $row[ 'info' ][ 'askPrice' ] ) / 2 );
    }

    return $ticker;

  }

  public function withdraw( $coin, $amount, $address, $tag = null ) {

    $coin = $this->coinNames[ $coin ];
    if ( floatval( $amount ) === 0 ) {
      // Simulate an error which ccxt may not raise for us
      return false;
    }

    return $this->checkAPIReturnValue( $this->exchange->withdraw( $coin, formatBTC( $amount ), $address, $tag ) );

  }

  public function getDepositAddress( $coin ) {

    $coin = $this->coinNames[ $coin ];
    $result = $this->exchange->fetch_deposit_address( $coin );
    if ( !isset( $result[ 'address' ] ) ) {
      logg( $this->prefix() . "Please generate a deposit address for $coin!", true );
      return null;
    }
    if ( !is_null( @$result[ 'tag' ] ) &&
         strlen( @$result[ 'tag' ] ) > 0 ) {
      return array( $result[ 'address' ], $result[ 'tag' ] );
    }
    return $result[ 'address' ];

  }

  public function buy( $tradeable, $currency, $rate, $amount ) {

    $tradeableInternal = $this->coinNames[ $tradeable ];
    $currencyInternal = $this->coinNames[ $currency ];
    $result = $this->exchange->create_order( $tradeableInternal . '/' . $currencyInternal, 'limit', 'buy',
                                             $amount, $rate );
    return $currency . '_' . $tradeable . ':' . $result[ 'id' ];

  }

  public function sell( $tradeable, $currency, $rate, $amount ) {

    $tradeableInternal = $this->coinNames[ $tradeable ];
    $currencyInternal = $this->coinNames[ $currency ];
    $result = $this->exchange->create_order( $tradeableInternal . '/' . $currencyInternal, 'limit', 'sell',
                                             $amount, $rate );
    return $currency . '_' . $tradeable . ':' . $result[ 'id' ];

  }

  public function getFilledOrderPrice( $type, $tradeable, $currency, $id ) {

    $tradeable = $this->coinNames[ $tradeable ];
    $currency = $this->coinNames[ $currency ];
    if (!preg_match( '/^[A-Z0-9_]+:(.*)$/', $id, $matches )) {
      throw new Exception( $this->prefix() . "Invalid order id: " . $id);
    }
    $orderNumber = $matches[ 1 ];
    $result = $this->exchange->fetch_my_trades( $tradeable . '/' . $currency );

    foreach ($result as $entry) {
      if ($entry[ 'order' ] == $orderNumber) {
        $factor = ($type == 'sell') ? -1 : 1;
        return floatval( $entry[ 'cost' ] ) + $factor * floatval( $this->getTradeFee( $entry, $type ) );
      }
    }
    return null;
  }

  public function queryTradeHistory( $options = array( ), $recentOnly = false ) {
    $results = array( );

    // Since this exchange was added after merging of the pl-rewrite branch, we don't
    // need the full trade history for the initial import, so we can ignore $recentOnly!

    $pairs = $this->getWithdrawablePairs();
    foreach ( $pairs as $pair ) {
      $result = $this->exchange->fetch_my_trades( str_replace( '_', '/', $pair ) );
  
      foreach ( $result as $row ) {
        $arr = explode( '/', $row[ 'symbol'] );
        $currency = strtoupper( $arr[ 1 ] );
        $tradeable = strtoupper( $arr[ 0 ] );
        $market = $tradeable . '_' . $currency;
  
        if (!in_array( $market, array_keys( $results ) )) {
          $results[ $market ] = array();
        }
        $feeFactor = ($row[ 'side' ] == 'sell') ? -1 : 1;
  
        $results[ $market ][] = array(
          'rawID' => $row[ 'order' ],
          'id' => $currency . '_' . $tradeable . ':' . $row[ 'order' ],
          'currency' => $currency,
          'tradeable' => $tradeable,
          'type' => $row[ 'side' ],
          'time' => floor( $row[ 'timestamp' ] / 1000 ), // timestamp is in milliseconds
          'rate' => floatval( $row[ 'price' ] ),
          'amount' => floatval( $row[ 'amount' ] ),
          'fee' => $this->getTradeFee( $row, $row[ 'side' ] ),
          'total' => floatval( $row[ 'cost' ] ),
        );
      }
    }

    foreach ( array_keys( $results ) as $market ) {
      usort( $results[ $market ], 'compareByTime' );
    }

    return $results;
  }

  private function getTradeFee( $row, $type ) {

    $fee = floatval( $row[ 'fee' ][ 'cost' ] );
    if ( $type == 'buy' ) {
      // On the buy side, the fee is returned in tradeable units, so we need to convert it to currency.
      $fee *= floatval( $row[ 'price' ] );
    }
    return $fee;

  }

  public function getRecentOrderTrades( &$arbitrator, $tradeable, $currency, $type, $orderID, $tradeAmount ) {

    if (!preg_match( '/^([A-Z0-9]+)_([A-Z0-9]+):(.*)$/', $orderID, $matches )) {
      throw new Exception( $this->prefix() . "Invalid order id: " . $orderID );
    }
    $currency = $matches[ 1 ];
    $tradeable = $matches[ 2 ];
    $currencyInternal = $this->coinNames[ $currency ];
    $tradeableInternal = $this->coinNames[ $tradeable ];
    $rawOrderID = $matches[ 3 ];
    $results = $this->exchange->fetch_my_trades( $tradeableInternal . '/' . $currencyInternal );

    $trades = array( );
    $feeFactor = ($type == 'sell') ? -1 : 1;
    foreach ( $results as $row ) {
      if ( $row[ 'order' ] != $rawOrderID ) {
        continue;
      }
      $trades[] = array(
        'rawID' => $rawOrderID . '/' . $row[ 'id' ],
        'id' => $rawOrderID,
        'currency' => $currency,
        'tradeable' => $tradeable,
        'type' => $type,
        'time' => floor( $row[ 'timestamp' ] / 1000 ), // timestamp is in milliseconds
        'rate' => floatval( $row[ 'price' ] ),
        'amount' => floatval( $row[ 'amount' ] ),
        'fee' => $this->getTradeFee( $row, $type ),
        'total' => floatval( $row[ 'cost' ] ),
      );
    }

    return $trades;

  }

  protected function fetchOrderbook( $tradeable, $currency ) {

    $tradeableInternal = $this->coinNames[ $tradeable ];
    $currencyInternal = $this->coinNames[ $currency ];
    $orderbook = $this->exchange->fetch_order_book( $tradeableInternal . '/' . $currencyInternal );
    if ( count( $orderbook ) == 0 ) {
      return null;
    }

    if ( !key_exists( 'asks', $orderbook ) ) {
      return null;
    }
    $asks = $orderbook[ 'asks' ];
    if ( count( $asks ) == 0 ) {
      return null;
    }
    $bestAsk = $asks[ 0 ];

    if ( !key_exists( 'bids', $orderbook ) ) {
      return null;
    }
    $bids = $orderbook[ "bids" ];
    if ( count( $bids ) == 0 ) {
      return null;
    }
    $bestBid = $bids[ 0 ];

    return new Orderbook( //
            $this, $tradeable, //
            $currency, //
            new OrderbookEntry( $bestAsk[ 1 ], $bestAsk[ 0 ] ), //
            new OrderbookEntry( $bestBid[ 1 ], $bestBid[ 0 ] ) //
    );

  }

  public function cancelOrder( $orderID ) {

    $split = explode( ':', $orderID );
    $pair = $split[ 0 ];
    $id = $split[ 1 ];
    $split = explode( '_', $pair );
    $currency = $split[ 0 ];
    $tradeable = $split[ 1 ];
    $currency = $this->coinNames[ $currency ];
    $tradeable = $this->coinNames[ $tradeable ];

    try {
      $this->exchange->cancel_order( $id, $tradeable . '/' . $currency );
      return true;
    }
    catch ( Exception $ex ) {
      return false;
    }

  }

  public function cancelAllOrders() {

    $pairs = $this->getWithdrawablePairs();
    foreach ( $pairs as $pair ) {
      $orders = $this->exchange->fetch_open_orders( str_replace( '_', '/', $pair ) );
      foreach ( $orders as $order ) {
        $orderID = $order[ 'order' ];
        $split = explode( '/', $order[ 'symbol' ] );
        $tradeable = $split[ 0 ];
        $currency = $split[ 1 ];
        $tradeable = $this->coinNames[ $tradeable ];
        $currency = $this->coinNames[ $currency ];
        $this->cancelOrder( $currency . '_' . $tradeable . ':' . $orderID );
      }
    }

  }

  public function refreshExchangeData() {

    $pairs = [ ];
    $precisions = [ ];
    $limits = [ ];
    $tradeFees = [ ];
    $conf = [ ];
    $markets = $this->exchange->loadMarkets();

    foreach ( $markets as $market ) {

      $tradeable = $market[ 'base' ];
      $currency = $market[ 'quote' ];

      $this->coinNames[ strtoupper( $tradeable ) ] = $tradeable;
      $this->coinNames[ strtoupper( $currency ) ] = $currency;

      if ( !Config::isCurrency( $currency ) ||
           Config::isBlocked( $tradeable ) ) {
        continue;
      }

      if ( !$this->isMarketActive( $market ) ) {
        continue;
      }

      $pair = strtoupper( $tradeable . "_" . $currency );
      $pairs[] = $pair;
      $precisions[ $pair ] = $market[ 'precision' ];
      $limits[ $pair ] = $market[ 'limits' ];
      // We can't be sure whether we'll be the maker or the taker in each trade, so we'll assume the worst.
      $tradeFees[ $pair ] = max( $market[ 'maker' ], $market[ 'taker' ] );
      $conf[ $tradeable ] = 0; // unknown!
    }

    $this->pairs = $pairs;
    $this->confirmationTimes = $conf;
    $this->tradeFees = $tradeFees;
    $this->precisions = $precisions;
    $this->limits = $limits;
    $this->withdrawFees = $this->exchange->fees[ 'funding' ][ 'withdraw' ];
    $this->depositFees = $this->exchange->fees[ 'funding' ][ 'deposit' ];

    $this->calculateTradeablePairs();

  }

  public function getPrecision( $tradeable, $currency ) {

    $pair = $tradeable . '_' . $currency;
    return $this->precisions[ $pair ];

  }

  public function getLimits( $tradeable, $currency ) {

    $pair = $tradeable . '_' . $currency;
    return $this->limits[ $pair ];

  }

  public function detectStuckTransfers() {

    $info = $this->getDepositHistory();
    if ( is_array( $info[ 'history' ] ) ) {
      if ( !is_array( $info[ 'pending' ] ) ) {
        $info[ 'pending' ] = [ $info[ 'pending' ] ];
      }

      $this->detectStuckTransfersInternal( $info[ 'history' ], 'deposit', $info );
    }

    $info = $this->getWithdrawalHistory();
    if ( is_array( $info[ 'history' ] ) ) {
      if ( !is_array( $info[ 'pending' ] ) ) {
        $info[ 'pending' ] = [ $info[ 'pending' ] ];
      }

      $this->detectStuckTransfersInternal( $info[ 'history' ], 'withdraw', $info );
    }

  }

  private function detectStuckTransfersInternal( $history, $key, $info ) {

    foreach ( $history as $entry ) {

      $timestamp = floor( $entry[ $info[ 'timeKey' ] ] / 1000 ); // in milliseconds
      if ( key_exists( $key, $this->lastStuckReportTime ) && $timestamp < $this->lastStuckReportTime[ $key ] ) {
        continue;
      }

      $status = $entry[ $info[ 'statusKey' ] ];
      if ( $timestamp < time() - 12 * 3600 && in_array( $status, $info[ 'pending' ] ) ) {
        alert( 'stuck-transfer', $this->prefix() .
               "Stuck $key! Please investigate and open support ticket if neccessary!\n\n" .
               print_r( $entry, true ), true );
        $this->lastStuckReportTime[ $key ] = $timestamp;
      }
    }

  }

  public function detectDuplicateWithdrawals() {

    $info = $this->getWithdrawalHistory();
    if ( is_array( $info[ 'history' ] ) ) {
      if ( !is_array( $info[ 'completed' ] ) ) {
        $info[ 'completed' ] = [ $info[ 'completed' ] ];
      }
      $history = $info[ 'history' ];
      $prevTimestamp = 0;
      $prevTxId = '';
      $prevAmount = '';
      $prevAddress = '';

      foreach ( $history as $entry ) {
        $timestamp = floor( $entry[ $info[ 'timeKey' ] ] / 1000 ); // in milliseconds
        if ( $timestamp < $this->lastDuplicateWithdrawalTime ) {
          continue;
        }
        $txid = $entry[ $info[ 'txidKey' ] ];
        $amount = $entry[ $info[ 'amountKey' ] ];
        $address = $entry[ $info[ 'addressKey' ] ];
        $status = $entry[ $info[ 'statusKey' ] ];
        $matched = false;
        if ( $timestamp == $prevTimestamp &&
             $txid == $prevTxId &&
             $amount == $prevAmount &&
             $address == $prevAddress &&
             in_array( $status, $info[ 'completed' ] ) ) {
          $matched = true;
        }
        $prevTimestamp = $timestamp;
        $prevTxId = $txid;
        $prevAmount = $amount;
        $prevAddress = $address;

        if ( $timestamp < time() - 24 * 3600 && $matched ) {
          alert( 'duplicate-withdrawal', $this->prefix() . "Duplicate withdrawal! Please investigate and open support ticket at " . ucwords( strtolower( $this->getName() ) ) . " if necessary!\r\r" . print_r( $entry, true ), true );
        }
      }
    }

  }

  public function dumpWallets() {

    logg( $this->prefix() . print_r( $this->queryBalances(), true ) );

  }

  private function queryBalances() {
    return $this->exchange->fetch_balance()[ 'free' ];
  }


  public function refreshWallets( $tradesMade = array() ) {

    $this->preRefreshWallets();

    $wallets = [ ];

    $arr = $this->queryBalances();
    foreach ( $arr as $coin => $balance ) {
      $wallets[ strtoupper( $coin ) ] = $balance;
    }

    $this->wallets = $wallets;

    $this->postRefreshWallets( $tradesMade );

  }

  public function testAccess() {

    $this->queryBalances();

  }

  public function getSmallestOrderSize( $tradeable, $currency, $type ) {

    $pair = $tradeable . "_" . $currency;
    if ( !isset( $this->limits[ $pair ][ 'cost' ][ 'min' ] ) ) {
      return 0;
    }
    return $this->limits[ $pair ][ 'cost' ][ 'min' ];

  }

  public function getID() {

    return $this->id;

  }

  public function getName() {

    return strtoupper( $this->name );

  }

  public function getTradeHistoryCSVName() {

    // See the comment in queryTradeHistory().
    return null;

  }

}
