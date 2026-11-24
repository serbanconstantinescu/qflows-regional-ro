<?php

/*
    CLD: 2026-11-24T15:00:00+02:00: the client of FGO's API v2 (REST over JSON, https://api.fgo.ro/v2, test https://api-testuat.fgo.ro/v2; reference https://api.fgo.ro/docs/scalar),
    for the FGO step and credential. Every request carries the company's API key (Authorization: Bearer fgo_api_v2...) and its CUI (Fgo-Cod-Unic, digits only); the writes that
    FGO checks against the company's sites (issue, cancel, reverse, delete) also Fgo-Url-Platforma: the credential's address, else this installation's.
    The answer: { data, meta }; an error is application/problem+json (RFC 9457: type, detail, validation_errors, motiv), a \RuntimeException with its text.
    FGO allows one call a second per operation and company: a 429 is waited for (Retry-After, while the step has time) and sent again (it was refused, so also an issue is safe).
	$f->call($method, $path, $query, $body, $platform)    the answer's `data`
	$f->all($path, $query, $max)                          a list read page by page (cursor): [ items, more ]
	$f->path($serie, $numar, $tail)                       facturi/{serie}/{numar}/$tail, encoded
*/

namespace plugins\RegionalRO\util;

class Fgo {
    const PRODUCTION = 'https://api.fgo.ro/v2/';
    const TEST = 'https://api-testuat.fgo.ro/v2/';
    //the items asked for in a page of a list (FGO serves at most its own ceiling)
    const PAGE = 500;
    //the 429s waited for in a call
    const RETRIES = 3;

    protected $cred;
    protected $timeout;
    protected $start;

    public function __construct($cred, $timeout = 60) {
	$this->cred = $cred;
	$this->timeout = max(1, (int)$timeout);
	$this->start = microtime(true);
    }

    protected function base() {
	return $this->cred->testEnv ? self::TEST : self::PRODUCTION;
    }

    public function cui() {
	return Anaf::cui($this->cred->cif) ?? preg_replace('/\D/', '', (string)$this->cred->cif);
    }

    public function platformUrl() {
	return trim((string)$this->cred->platformUrl) ?: rtrim(\V::app()->createAbsoluteUrl('/'), '/');
    }

    public static function path($serie, $numar, $tail = '') {
	return 'facturi/' . rawurlencode((string)$serie) . '/' . rawurlencode((string)$numar) . ($tail !== '' ? '/' . $tail : '');
    }

    public function call($method, $path, array $query = [], $body = null, $platform = false) {
	return $this->send($method, $path, $query, $body, $platform)['data'] ?? null;
    }

    //the whole answer { data, meta }
    protected function send($method, $path, array $query = [], $body = null, $platform = false) {
	$headers = [ 'Authorization' => 'Bearer ' . trim((string)$this->cred->apiKey), 'Fgo-Cod-Unic' => $this->cui(), 'Accept' => 'application/json' ];
	if ($platform)
	    $headers['Fgo-Url-Platforma'] = $this->platformUrl();
	$o = [ 'headers' => $headers ];
	if ($body !== null)
	    $o['json'] = $body;
	$url = $this->base() . ltrim($path, '/') . Rest::query($query);
	for ($try = 0; ; $try++) {
	    $r = Rest::send($method, $url, $o, $this->start, $this->timeout);
	    if ($r['status'] != 429 || $try >= self::RETRIES)
		break;
	    $wait = max(1, (int)($r['headers']['retry-after'] ?? 1));
	    if ($wait >= Anaf::left($this->start, $this->timeout))
		break;
	    sleep($wait);
	}
	$d = $r['data'];
	if ($r['status'] < 200 || $r['status'] >= 300)
	    throw new \RuntimeException(self::problem($r['status'], $d));
	if (!is_array($d))
	    throw new \RuntimeException("FGO's answer is not JSON (HTTP {$r['status']})");
	return $d;
    }

    //a list of FGO's (GET, cursor pages): its items until $max (one more tells there are more), a second between the pages (FGO's limit)
    public function all($path, array $query, $max) {
	$items = [];
	$cursor = null;
	do {
	    if ($cursor !== null)
		sleep(1);
	    $r = $this->send('GET', $path, $query + [ 'limit' => min(self::PAGE, $max + 1) ] + ($cursor !== null ? [ 'cursor' => $cursor ] : []));
	    array_push($items, ...array_values((array)($r['data'] ?? [])));
	    $cursor = $r['meta']['pagination']['next_cursor'] ?? null;
	} while ($cursor !== null && $cursor !== '' && count($items) <= $max);
	return [ $items, count($items) > $max || ($cursor !== null && $cursor !== '') ];
    }

    //an error's text: its detail (else title), the reason (motiv.mesaj), the fields it names (validation_errors), the HTTP status
    public static function problem($status, $d) {
	if (!is_array($d))
	    return "FGO refused the request (HTTP {$status})" . (($m = Rest::message($d)) !== '' ? ": {$m}" : '');
	$parts = [ trim((string)($d['detail'] ?? '')) ?: trim((string)($d['title'] ?? '')) ];
	if (trim((string)($d['motiv']['mesaj'] ?? '')) !== '' && !in_array(trim((string)$d['motiv']['mesaj']), $parts, true))
	    $parts[] = trim((string)$d['motiv']['mesaj']);
	foreach(array_slice((array)($d['validation_errors'] ?? []), 0, 10) as $v) {
	    $where = (string)($v['pointer'] ?? $v['name'] ?? '');
	    foreach((array)($v['issues'] ?? []) as $i)
		$parts[] = ($where !== '' ? "{$where}: " : '') . (string)($i['message'] ?? $i['code'] ?? '');
	}
	$parts = array_values(array_filter($parts, fn($p) => $p !== ''));
	$type = preg_replace('~^urn:fgo:api:v2:problem:~', '', (string)($d['type'] ?? ''));
	return ($parts ? mb_substr(implode('; ', $parts), 0, 1000) : 'FGO refused the request') . " (HTTP {$status}" . ($type !== '' ? ", {$type}" : '') . ')';
    }
}
