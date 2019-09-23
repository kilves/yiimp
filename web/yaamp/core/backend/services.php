<?php

/* NiceHash Stuff */

// Return X-Auth header for given nicehash request parameters
function get_nicehash_auth($apikey, $apisecret, $time, $nonce, $organization, $method, $path, $querystring, $body, $json)
{
    $data =
        mb_convert_encoding($apikey, "ISO-8859-1") . "\0" .
        mb_convert_encoding($time, "ISO-8859-1") . "\0" .
        mb_convert_encoding($nonce, "ISO-8859-1") . "\0" .
        "\0" .
        mb_convert_encoding($organization, "ISO-8859-1") . "\0" .
        "\0" .
        mb_convert_encoding($method, "ISO-8859-1") . "\0" .
        mb_convert_encoding($path, "ISO-8859-1") . "\0" .
        mb_convert_encoding($querystring, "ISO-8859-1") . "\0";
    if ($json) {
        $data .= mb_convert_encoding($body, "UTF-8");
    } else {
        $data .= $body;
    }
    return hash_hmac("sha256",  $data, $apisecret);
}

// Build a nicehash API v2 request and execute it, returning result.
function execute_nicehash_request($url, $method, $body, $public, $json) {
    if ($public) {
        // Public requests don't need signing headers
        $s = curl_init();
        curl_setopt($s, CURLOPT_URL, "https://api2.nicehash.com" . $url);
        curl_setopt($s, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($s);
        curl_close($s);
        if ($res === false) {
            return null;
        }
        return $res;
    }
    $apikey = NICEHASH_API_KEY;
    $url_ary = explode("?", $url);
    $path = $url_ary[1];
    $querystring = $url_ary[2];
    $apisecret = ""; // TODO add apisecret to config
    $organization = ""; // TODO add organisation to config
    $time = round(microtime(true) * 1000);
    $nonce = gen_uuid();
    $request_id = gen_uuid(); // Just generate an uuid, this field isn't really applicable here.
    $auth = get_nicehash_auth($apikey, $apisecret, $time, $nonce, $organization, $method, $path, $querystring, $body, $json);

    $curl_opts = array(
        "X-Time: " . $time,
        "X-Nonce: " . $nonce,
        "X-Organization-Id: " . $organization,
        "X-Request-Id: " . $request_id,
        "X-Auth: " . $apikey . ":" . $auth
    );

    if ($json) {
        array_push($curl_opts, "Accept: application/json;charset=UTF-8");
    }

    $s = curl_init();
    curl_setopt($s, CURLOPT_URL, "https://api2.nicehash.com" . $url);
    curl_setopt($s, CURLOPT_HTTPHEADER, $curl_opts);
    curl_setopt($s, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($s);
    curl_close($s);
    if ($res === false) {
        return null;
    }
    return $res;
}

// Turn a decimal number to API v2 compliant string value
function nicehash_num2str($num) {
    return number_format($num, 8, ".", "");
}

function nicehash_account($currency) {
    return execute_nicehash_request(
        "/main/api/v2/accounting/account/" . $currency, "GET",
        "",
        false, false
    );
}

function nicehash_stats_global_current() {
    return execute_nicehash_request(
        "/main/api/v2/public/stats/global/current/", "GET",
        "",
        true, false
    );
}

function nicehash_order_remove($id) {
    return execute_nicehash_request(
        "/main/api/v2/hashpower/order/" . $id, "DELETE",
        "",
        false, false
    );
}

function nicehash_orders_get_my($algo) {
    return execute_nicehash_request(
        "/main/api/v2/hashpower/myOrders?algo=" . $algo . "&limit=1", "GET",
        "",
        false, false
    );
}

function nicehash_order_create($type, $limit, $poolId, $price, $marketFactor, $displayMarketFactor, $amount, $algorithm, $market) {
    $body = array(
        "type" => $type,
        "limit" => nicehash_num2str($limit),
        "poolId" => $poolId,
        "price" => nicehash_num2str($price),
        "marketFactor" => nicehash_num2str($marketFactor),
        "displayMarketFactor" => $displayMarketFactor,
        "amount" => nicehash_num2str($amount),
        "algorithm" => $algorithm,
        "market" => $market
    );
    return execute_nicehash_request(
        "/main/api/v2/hashpower/order", "POST",
        json_encode($body),
        false, true
    );
}

function nicehash_order_update_price_and_limit($order, $price, $limit) {
    $body = array(
        "marketFactor" => nicehash_num2str($order->marketFactor),
        "displayMarketFactor" => nicehash_num2str($order->displayMarketFactor),
        "limit" => nicehash_num2str($limit),
        "price" => nicehash_num2str($price)
    );
    return execute_nicehash_request(
        "/main/api/v2/hashpower/order/" . $order->id . "/updatePriceAndLimit","POST",
        json_encode($body),
        false, true
    );
}

function nicehash_order_refill($id, $amount) {
    $body = array(
        "amount" => nicehash_num2str($amount)
    );
    return execute_nicehash_request(
        "/main/api/v2/hashpower/order/" . $id . "/refill", "POST",
        json_encode($body),
        false, true
    );
}

function nicehash_get_pools() {
    return execute_nicehash_request(
        "/main/api/v2/pools/", "GET",
        "",
        false, false
    );
}

function nicehash_create_pool($status, $host, $port, $username, $password, $algo, $name) {
    $body = array(
        "status" => $status,
        "password" => $password,
        "username" => $username,
        "stratumPort" => $port,
        "stratumHostname" => $host,
        "algorithm" => $algo,
        "name" => $name
    );
    return execute_nicehash_request(
        "/main/api/v2/pool/", "POST",
        json_encode($body),
        false, true
    );
}

// Returns pool ID for specified pool data. Creates pool for organization if it doesn't already exist.
// This is required because API v2 removed support for directly providing pool parameters.
function nicehash_get_pool_id($host, $port, $username, $password, $algorithm) {
    // Check if a pool already exists
    $res = nicehash_get_pools();
    if (!$res) {
        throw new Exception("Failed to request pools");
    }
    $data = json_decode($res);
    foreach ($data->list as $pool) {
        if ($pool->host == $host && $pool->port == $port && $pool->username == $username && $pool->password == $password && $pool->algorithm == $algorithm) {
            // Found a pool matching parameters
            return $pool->id;
        }
    }

    // No pool found, create it
    $res = nicehash_create_pool("VERIFIED", $host, $port, $username, $password, $algorithm, "YAAMP pool");
    if (!$res) {
        throw new Exception("Failed to create pool");
    }
    $data = json_decode($res);
    return $data->id;
}

function BackendUpdateServices()
{
//	debuglog(__FUNCTION__);
	if (YAAMP_USE_NICEHASH_API != true)
		return;

	$table = array(
		0=>'scrypt',
		1=>'sha256',
		2=>'scryptn',
		3=>'x11',
		4=>'x13',
		5=>'keccak',
		6=>'x15',
		7=>'nist5',
		8=>'neoscrypt',
		9=>'lyra2',
		10=>'whirlx',
		11=>'qubit',
		12=>'quark',
		// 13=>'Axiom',
		14=>'lyra2v2', // 14 = Lyra2REv2
		// 15=>'ScryptJaneNf16', // 15 = ScryptJaneNf16
		16=>'blakecoin', // 16 = Blake256r8
		// 17=>'Blake256r14',
		// 18=>'Blake256r8vnl',
		// 19=>'Hodl',
		// 20=>'DaggerHashimoto',
		// 21=>'Decred',
		// 22=>'CryptoNight',
		23=>'lbry',
		24=>'equihash',
		// 25=>'Pascal',
		26=>'sib', // X11Gost
		// 27=>'Sia',
		28=>'blake2s',
		29=>'skunk',
	);

	$res = nicehash_stats_global_current();
	if(!$res) return;

	$a = json_decode($res);
	if(!$a || !isset($a->result)) return;

	foreach($a->result->stats as $stat)
	{
		if($stat->price <= 0) continue;
		if(!isset($table[$stat->algo])) continue;
		$algo = $table[$stat->algo];

		$service = getdbosql('db_services', "name='Nicehash' and algo=:algo", array(':algo'=>$algo));
		if(!$service)
		{
			$service = new db_services;
			$service->name = 'Nicehash';
			$service->algo = $algo;
		}

		$service->price = $stat->price/1000;
		$service->speed = $stat->speed*1000000000;
		$service->save();

		$list = getdbolist('db_jobs', "percent>0 and algo=:algo and (host='stratum.westhash.com' or host='stratum.nicehash.com')", array(':algo'=>$algo));
		foreach($list as $job)
		{
			$job->price = round($service->price*1000*(100-$job->percent)/100, 2);
			$job->save();
		}
	}

	$list = getdbolist('db_renters', "custom_address is not null and custom_server is not null");
	foreach($list as $renter)
	{
		$res = fetch_url("https://$renter->custom_server/api?method=stats.provider&addr=$renter->custom_address");
		if(!$res) continue;

		$renter->custom_balance = 0;
		$renter->custom_accept = 0;
		$renter->custom_reject = 0;

		$a = json_decode($res);
		foreach($a->result->stats as $stat)
		{
			if(!isset($table[$stat->algo])) continue;
			$algo = $table[$stat->algo];

			$renter->custom_balance += $stat->balance;
			$renter->custom_accept += $stat->accepted_speed*1000000000;
		}

		$renter->save();
	}

	///////////////////////////////////////////////////////////////////////////

	// renting from nicehash
	if (YAAMP_USE_NICEHASH_API != true)
		return;

	$deposit = NICEHASH_DEPOSIT;
	$amount = NICEHASH_DEPOSIT_AMOUNT;

	$res = nicehash_account("BTC");
	debuglog($res);

	$a = json_decode($res);
	$balance = $a->balance;

	foreach($table as $i=>$algo)
	{
		$nicehash = getdbosql('db_nicehash', "algo=:algo", array(':algo'=>$algo));
		if(!$nicehash)
		{
			$nicehash = new db_nicehash;
			$nicehash->active = false;
			$nicehash->algo = $algo;
		}

		if(!$nicehash->active)
		{
			if($nicehash->orderid)
			{
			    $res = nicehash_order_remove($nicehash->orderId);
				debuglog($res);

				$nicehash->orderid = null;
			}

			$nicehash->btc = null;
			$nicehash->price = null;
			$nicehash->speed = null;
			$nicehash->last_decrease = null;

			$nicehash->save();
			continue;
		}

		$price = dboscalar("select price from hashrate where algo=:algo order by time desc limit 1", array(':algo'=>$algo));
		$minprice = $price*0.5;
		$setprice = $price*0.7;
		$maxprice = $price*0.9;
		$cancelprice = $price*1.1;

		$res = nicehash_orders_get_my($algo);
		if(!$res) break;

		$a = json_decode($res);
		if(count($a->list) == 0)
		{
			if($balance < $amount) continue;
			$port = getAlgoPort($algo);

			$poolId = nicehash_get_pool_id("yaamp.com", $port, $deposit, "xx", $algo);
			$res = nicehash_order_create("STANDARD", 0, $poolId, $setprice, 100000, 100000, $amount, $algo, "EU");
			debuglog($res);

			$nicehash->last_decrease = time();
			$nicehash->save();

			continue;
		}

		$order = $a->list[0];
		debuglog("$algo $order->price $minprice $setprice $maxprice $cancelprice");

		$nicehash->orderid = $order->id;
		$nicehash->btc = $order->availableAmount;
		$nicehash->workers = $order->rigsCount;
		$nicehash->price = $order->price;
		$nicehash->speed = $order->limit;
		$nicehash->accepted = $order->acceptedCurrentSpeed;

		if($order->price > $cancelprice && $order->rigsCount > 0)
		{
			debuglog("* cancel order $algo");

			$res = nicehash_order_remove($order->id);
			debuglog($res);
		}

		else if($order->price > $maxprice && $order->limit == 0)
		{
			debuglog("* decrease speed $algo");
            $res = nicehash_order_update_price_and_limit($order, $order->price, 0.05);
			debuglog($res);
		}

		else if($order->price > $maxprice && $nicehash->last_decrease+10*60 < time())
		{
			debuglog("* decrease price $algo");

			nicehash_order_update_price_and_limit($order, $maxprice, $order->limit);
			debuglog($res);

			$nicehash->last_decrease = time();
		}

		else if($order->price < $minprice && $order->rigsCount <= 0)
		{
			debuglog("* increase price $algo");
            nicehash_order_update_price_and_limit($order, $setprice, $order->limit);
			debuglog($res);
		}

		else if($order->price < $maxprice && $order->limit == 0.05)
		{
			debuglog("* increase speed $algo");
			nicehash_order_update_price_and_limit($order, $order->price, 0);
			debuglog($res);
		}

		else if($order->availableAmount < 0.00075000)
		{
			debuglog("* refilling order $order->id");
            $res = nicehash_order_refill($order->id, 0.01);
			debuglog($res);
		}

		$nicehash->save();
	}

}
