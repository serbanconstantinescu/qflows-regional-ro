<?php

/*
    CLD: 2026-11-24T15:00:00+02:00: FGO - invoices in FGO, on FGO's API v2 (util\Fgo), for the credential's company. Operations (setting `operation`):
	invoice       issue an invoice (POST facturi): the body as FGO names it (snake_case: client, articole, ...; serie from Series, valuta RON and tip_factura F when it has
	              none): { serie, numar, stocuri }
	invoices      the invoices issued (GET facturi): from / to (FGO: the last 30 days, at most a year), the client's CUI, paid or not
	status        an invoice's value, what was paid and its payments (GET facturi/{serie}/{numar})
	print         an invoice's PDF (genereaza-link-de-descarcare): its link, and the PDF saved in the project's Files (Save the PDF)
	paymentLink   an invoice's payment link (genereaza-link-de-plata; a payment processor set up in FGO)
	email         send an invoice by email (trimite-pe-email; empty address: the client's)
	efactura      an invoice's last e-Factura event (GET .../efactura; null: none yet)
	spv           send an invoice to ANAF's SPV (trimite-in-spv)
	cancel / delete / reverse   an invoice (anuleaza, DELETE, storneaza: the credit note's series, number, date optional)
	awb           attach a courier's AWB to an invoice (PUT .../awb)
	payment       record a payment of an invoice (incasari; FGO: Premium and Enterprise): amount, type (nomenclature tipuri-incasare), date, account
	nomenclature  one of FGO's lists (nomenclatoare/...: countries, counties, localities of a county, VAT rates, banks, currencies, invoice and payment types)
	articles / warehouses       the articles (Enterprise), the warehouses
	api           Call the API: any path after /v2/ (Fgo-Cod-Unic and the key added; Fgo-Url-Platforma too, but to GET)
    FGO's lists need the fields they give (`fields`): all of each list's are asked (FIELDS)
*/

namespace plugins\RegionalRO\models\node;

use plugins\RegionalRO\util\Fgo;

class NodeAppRoFgo extends NodeAppRo {
    public static $name = 'ro-fgo';
    public static $label = 'FGO';
    public static $icon = 'bi-receipt-cutoff';
    public static $defaultOperation = 'invoice';
    public static $defaultVariable = 'fgo';
    public static $capability = 'fgo';
    public static $credentialLabel = 'FGO credential';

    const OPERATIONS = [
	'invoice' => 'Issue an invoice',
	'invoices' => 'List invoices',
	'status' => 'Get an invoice\'s status',
	'print' => 'Get an invoice\'s PDF',
	'paymentLink' => 'Get an invoice\'s payment link',
	'email' => 'Email an invoice',
	'efactura' => 'Get the e-Factura status',
	'spv' => 'Send an invoice to SPV',
	'cancel' => 'Cancel an invoice',
	'delete' => 'Delete an invoice',
	'reverse' => 'Reverse an invoice',
	'awb' => 'Attach an AWB',
	'payment' => 'Record a payment',
	'nomenclature' => 'List a nomenclature',
	'articles' => 'List articles',
	'warehouses' => 'List warehouses',
	'api' => 'Call the API',
    ];
    const WRITES = [ 'invoice', 'email', 'spv', 'cancel', 'delete', 'reverse', 'awb', 'payment', 'api' ];
    const LISTS = [ 'invoices', 'nomenclature', 'articles', 'warehouses' ];
    const CREDENTIAL_OPS = [ 'invoice', 'invoices', 'status', 'print', 'paymentLink', 'email', 'efactura', 'spv', 'cancel', 'delete', 'reverse', 'awb', 'payment',
	'nomenclature', 'articles', 'warehouses', 'api' ];
    //the operations of one invoice (Series and Number)
    const ONE = [ 'status', 'print', 'paymentLink', 'email', 'efactura', 'spv', 'cancel', 'delete', 'reverse', 'awb', 'payment' ];
    const NOMENCLATURES = [ 'tari' => 'Countries', 'judete' => 'Counties', 'localitati' => 'Localities of a county', 'cote-tva' => 'VAT rates', 'banci' => 'Banks',
	'valute' => 'Currencies', 'tipuri-factura' => 'Invoice types', 'tipuri-incasare' => 'Payment types' ];
    //the fields FGO gives of each list (all asked)
    const FIELDS = [
	'facturi' => 'data_emitere,data_scadenta,valoare_totala,valoare_fara_tva,valoare_tva,valoare_ramasa,valuta,status,status_plata,trimisa_pe_email,id_client,client,cod_unic_client',
	'factura' => 'valoare,valoare_achitata,incasari(suma_incasata,data_incasare,valuta)',
	'articole' => 'cod,nume,cod_bare,um,pret_unitar,cota_tva,stoc',
	'gestiuni' => 'nume',
	'tari' => 'nume,cod', 'judete' => 'nume,cod', 'localitati' => 'nume', 'cote-tva' => 'valoare', 'banci' => 'nume,bic,alias', 'valute' => 'nume',
	'tipuri-factura' => 'nume,cod', 'tipuri-incasare' => 'nume',
    ];
    const PAID = [ '' => 'All', 'unpaid' => 'Not fully paid', 'paid' => 'Paid' ];

    protected static function operationFields() {
	return array_merge([
	    self::field('document', 'Document', [ 'invoice' ], [ 'type' => 'textarea', 'required' => true,
		'help' => 'A JSON object as FGO names it, e.g. {"client": {"denumire": "...", "tip": "PJ", "cod_unic": "...", "tara": "Romania", "cod_judet": "B", "localitate": "...", '
		    . '"adresa": "..."}, "articole": [{"denumire": "...", "nr_produse": 1, "um": "BUC", "cota_tva": 0.21, "pret_unitar": 100}]}; serie: from Series; valuta RON, '
		    . 'tip_factura F when not given; id_extern + "verificare_duplicat": true to issue it once',
		'ui' => [ 'rows' => 6, 'expression' => true, 'code' => true, 'agent' => [ 'type' => 'object' ] ] ]),
	    self::field('series', 'Series', array_merge([ 'invoice' ], self::ONE), [ 'help' => 'Of an invoice register in FGO; issuing: empty takes the document\'s serie',
		'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('number', 'Number', self::ONE, [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('savePdf', 'Save the PDF', [ 'print' ], [ 'type' => 'switch', 'default' => true, 'help' => 'In the project\'s Files; off: only its link', 'ui' => [ 'width' => 4 ] ]),
	    self::field('email', 'Email', [ 'email' ], [ 'help' => 'Empty: the client\'s', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('stornoSeries', 'Credit note series', [ 'reverse' ], [ 'help' => 'Empty: FGO\'s', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('stornoNumber', 'Credit note number', [ 'reverse' ], [ 'help' => 'Empty: the next', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('date', 'Date', [ 'reverse', 'payment' ], [ 'help' => 'YYYY-MM-DD; empty: today', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('awbNumber', 'AWB', [ 'awb' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('amount', 'Amount', [ 'payment' ], [ 'required' => true, 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('paymentType', 'Payment type', [ 'payment' ], [ 'required' => true, 'default' => 'Banca', 'help' => 'A name of the nomenclature Payment types (e.g. Banca)',
		'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('account', 'Collection account', [ 'payment' ], [ 'help' => 'As in FGO (Setari > Incasare); empty: the type\'s', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('from', 'Issued from', [ 'invoices' ], [ 'help' => 'YYYY-MM-DD; empty: 30 days ago (at most a year to To)', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('to', 'Issued to', [ 'invoices' ], [ 'help' => 'YYYY-MM-DD; empty: today', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('clientCui', 'Client CUI', [ 'invoices' ], [ 'help' => 'Empty: all the clients', 'ui' => [ 'expression' => 'text', 'width' => 4 ] ]),
	    self::field('paid', 'Paid', [ 'invoices' ], [ 'type' => 'select', 'default' => '', 'choices' => self::PAID, 'ui' => [ 'width' => 4 ] ]),
	    self::field('nomenclature', 'Nomenclature', [ 'nomenclature' ], [ 'type' => 'select', 'default' => 'cote-tva', 'choices' => self::NOMENCLATURES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('county', 'County code', [ 'nomenclature' ], [ 'help' => 'Of Localities (e.g. B, CJ)', 'ui' => [ 'expression' => 'text', 'width' => 4,
		'showIf' => [ 'key' => 'nomenclature', 'operator' => 'eq', 'value' => 'localitati' ] ] ]),
	], self::apiFields('After https://api.fgo.ro/v2/, e.g. facturi/FGO/1201/asociate (the key, Fgo-Cod-Unic and, but to GET, Fgo-Url-Platforma are added; a list asks fields=)',
	    'A JSON object sent as the body; empty: none'));
    }

    protected static function resultOf($operation) {
	if ($operation === 'invoice')
	    return [ 'type' => 'object', 'info' => '{ serie, numar, stocuri }', 'fields' => [ 'serie' => [ 'type' => 'string' ], 'numar' => [ 'type' => 'string' ],
		'stocuri' => [ 'type' => 'array', 'info' => 'the stock left of the articles, when FGO shows it' ] ] ];
	if ($operation === 'print')
	    return [ 'type' => 'object', 'info' => '{ link, file }', 'fields' => [ 'link' => [ 'type' => 'string' ], 'file' => [ 'type' => 'object', 'info' => '{ id, filename, size, contentType }' ] ] ];
	if ($operation === 'paymentLink')
	    return [ 'type' => 'object', 'info' => '{ link }', 'fields' => [ 'link' => [ 'type' => 'string' ] ] ];
	if ($operation === 'status')
	    return [ 'type' => 'object', 'info' => '{ serie, numar, valoare, valoare_achitata, incasari }', 'fields' => [ 'serie' => [ 'type' => 'string' ], 'numar' => [ 'type' => 'string' ],
		'valoare' => [ 'type' => 'number' ], 'valoare_achitata' => [ 'type' => 'number' ], 'incasari' => [ 'type' => 'array', 'info' => '{ suma_incasata, data_incasare, valuta }' ] ] ];
	return null;
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$invoice = function() use ($text) {
	    if ($text('series') === '' || $text('number') === '')
		throw new \RuntimeException('Series and Number: both needed');
	    return [ $text('series'), $text('number'), $text('series') . ' ' . $text('number') ];
	};
	switch($operation) {
	    case 'invoice':
		$doc = $this->jsonOf($value, 'document', 'Document');
		if (empty($doc['articole']) || !is_array($doc['articole']))
		    throw new \RuntimeException('Document: articole (at least one line) is needed');
		if ($text('series') !== '')
		    $doc['serie'] = $text('series');
		if (trim((string)($doc['serie'] ?? '')) === '')
		    throw new \RuntimeException('Series: none given, nor serie in the document');
		$doc += [ 'valuta' => 'RON', 'tip_factura' => 'F' ];
		$f = (array)$this->client->call('POST', 'facturi', [], $doc, true);
		return [ $f, 'invoice ' . trim(($f['serie'] ?? '') . ' ' . ($f['numar'] ?? '')) ];
	    case 'invoices':
		$paid = (string)$this->cfg('paid', '');
		$query = array_filter([ 'data_emitere_de_la' => $text('from') !== '' ? $this->dateOf($text, 'from', 'Issued from') : null,
		    'data_emitere_pana_la' => $text('to') !== '' ? $this->dateOf($text, 'to', 'Issued to') : null,
		    'cod_unic_client' => $text('clientCui') !== '' ? (\plugins\RegionalRO\util\Anaf::cui($text('clientCui')) ?? $text('clientCui')) : null,
		    'restanta' => $paid === 'unpaid' ? 'true' : ($paid === 'paid' ? 'false' : null), 'fields' => self::FIELDS['facturi'] ], fn($v) => $v !== null);
		[ $items ] = $this->client->all('facturi', $query, $this->maxItems());
		return $this->listResult($items, 'invoice');
	    case 'status':
		[ $s, $n, $name ] = $invoice();
		$r = (array)$this->client->call('GET', Fgo::path($s, $n), [ 'fields' => self::FIELDS['factura'] ]);
		return [ $r, "invoice {$name}: paid " . ($r['valoare_achitata'] ?? '?') . ' of ' . ($r['valoare'] ?? '?') ];
	    case 'print':
		[ $s, $n, $name ] = $invoice();
		$link = (string)($this->client->call('POST', Fgo::path($s, $n, 'genereaza-link-de-descarcare'))['link'] ?? '');
		if ($link === '')
		    throw new \RuntimeException('FGO gave no link to the PDF');
		$file = null;
		if ($this->cfg('savePdf', true)) {
		    $r = \plugins\RegionalRO\util\Rest::send('GET', $link, [ 'redirects' => 5 ], $this->start, $this->timeout);
		    if ($r['status'] >= 400 || !str_starts_with((string)$r['body'], '%PDF'))
			throw new \RuntimeException("the PDF could not be read (HTTP {$r['status']})");
		    $file = $this->saveFile("factura-{$s}-{$n}.pdf", $r['body']);
		}
		return [ [ 'link' => $link, 'file' => $file ], "invoice {$name}" . ($file ? ": {$file['filename']}" : ": {$link}") ];
	    case 'paymentLink':
		[ $s, $n, $name ] = $invoice();
		$link = (string)($this->client->call('POST', Fgo::path($s, $n, 'genereaza-link-de-plata'))['link'] ?? '');
		return [ [ 'link' => $link ], "invoice {$name}: {$link}" ];
	    case 'email':
		[ $s, $n, $name ] = $invoice();
		$r = $this->client->call('POST', Fgo::path($s, $n, 'trimite-pe-email'), [], $text('email') !== '' ? [ 'email' => $text('email') ] : (object)[]);
		return [ $r, "invoice {$name} sent" . ($text('email') !== '' ? ' to ' . $text('email') : '') ];
	    case 'efactura':
		[ $s, $n, $name ] = $invoice();
		$r = $this->client->call('GET', Fgo::path($s, $n, 'efactura'));
		return [ $r, "invoice {$name}: " . (is_array($r) ? (string)($r['status'] ?? '?') : 'no e-Factura event yet') ];
	    case 'spv':
		[ $s, $n, $name ] = $invoice();
		$r = $this->client->call('POST', Fgo::path($s, $n, 'trimite-in-spv'));
		return [ $r, "invoice {$name} sent to SPV" ];
	    case 'cancel':
		[ $s, $n, $name ] = $invoice();
		$r = $this->client->call('POST', Fgo::path($s, $n, 'anuleaza'), [], null, true);
		return [ $r, "invoice {$name} cancelled" ];
	    case 'delete':
		[ $s, $n, $name ] = $invoice();
		$r = $this->client->call('DELETE', Fgo::path($s, $n), [], null, true);
		return [ $r, "invoice {$name} deleted" ];
	    case 'reverse':
		[ $s, $n, $name ] = $invoice();
		$f = (array)$this->client->call('POST', Fgo::path($s, $n, 'storneaza'), [], (object)array_filter([ 'serie_storno' => $text('stornoSeries'),
		    'numar_storno' => $text('stornoNumber'), 'data_emitere' => $text('date') !== '' ? $this->dateOf($text) : '' ], fn($v) => $v !== ''), true);
		return [ $f, "invoice {$name} reversed" . (isset($f['numar']) ? ' by ' . trim(($f['serie'] ?? '') . ' ' . $f['numar']) : '') ];
	    case 'awb':
		[ $s, $n, $name ] = $invoice();
		$r = $this->client->call('PUT', Fgo::path($s, $n, 'awb'), [], [ 'awb' => $text('awbNumber') ]);
		return [ $r, "invoice {$name}: AWB " . $text('awbNumber') ];
	    case 'payment':
		[ $s, $n, $name ] = $invoice();
		$amount = str_replace([ ' ', ',' ], [ '', '.' ], $text('amount'));
		if (!is_numeric($amount))
		    throw new \RuntimeException('Amount: not a number');
		$r = (array)$this->client->call('POST', Fgo::path($s, $n, 'incasari'), [], array_filter([ 'suma_incasata' => round((float)$amount, 2),
		    'tip_incasare' => $text('paymentType'), 'data_incasare' => $this->dateOf($text), 'cont_incasare' => $text('account') ], fn($v) => $v !== ''));
		return [ $r, "invoice {$name}: {$amount} paid" . (isset($r['numar_incasare']) ? ' (' . trim(($r['serie_incasare'] ?? '') . ' ' . $r['numar_incasare']) . ')' : '') ];
	    case 'nomenclature':
		$type = isset(self::NOMENCLATURES[(string)$this->cfg('nomenclature', 'cote-tva')]) ? (string)$this->cfg('nomenclature', 'cote-tva') : 'cote-tva';
		$query = [ 'fields' => self::FIELDS[$type] ];
		if ($type === 'localitati') {
		    if ($text('county') === '')
			throw new \RuntimeException('County code: needed for the localities');
		    $query['cod_judet'] = $text('county');
		}
		[ $items ] = $this->client->all('nomenclatoare/' . $type, $query, $this->maxItems());
		return $this->listResult($items, 'value');
	    case 'articles':
		[ $items ] = $this->client->all('articole', [ 'fields' => self::FIELDS['articole'] ], $this->maxItems());
		return $this->listResult($items, 'article');
	    case 'warehouses':
		[ $items ] = $this->client->all('gestiuni', [ 'fields' => self::FIELDS['gestiuni'] ], $this->maxItems());
		return $this->listResult($items, 'warehouse');
	    case 'api':
		[ $method, $path, $query, $body ] = $this->apiCall($text, $value);
		if ($body !== null && (!is_array($body) || array_is_list($body)))
		    throw new \RuntimeException('Body: a JSON object');
		return [ $this->client->call($method, ltrim(preg_replace('~^v2/~', '', $path), '/'), $query, $body, $method !== 'GET'), "{$method} {$path}" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
