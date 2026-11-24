<?php

/*
    CLD: 2026-11-24T15:00:00+02:00: an FGO company's access to FGO's API v2, for the FGO step (util\Fgo): the company's CUI (Fgo-Cod-Unic), its API key (encrypted; FGO:
    eCommerce > API > Chei API, a Premium or Enterprise subscription), the site address FGO checks on issuing, cancelling, reversing and deleting (Fgo-Url-Platforma: one
    registered in FGO, Setari > eCommerce; empty: this installation's), FGO's test environment (api-testuat.fgo.ro, an account of testuat.fgo.ro).
    Test: the key and the CUI checked (GET identitate): the key's rights and expiry. Provides `fgo`
*/

namespace plugins\RegionalRO\models\credential;

class CredentialRoFgo extends \models\Credential {
    public static $label = 'FGO';
    public static $icon = 'bi-receipt-cutoff';
    public static $group = 'Romania';
    public static $provides = [ 'fgo' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'cif',
		'type' => 'text',
		'label' => 'Company CUI',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The company\'s fiscal code, the one of the API key (Fgo-Cod-Unic; RO is left out)',
		'validate' => [ [ 'regex', 'pattern' => '^(RO|ro)?\s*[1-9]\d{1,9}$', 'skipEmpty' => true, 'message' => 'A CUI, such as 14399840' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'API key',
		'required' => true,
		'encrypt' => true,
		'help' => 'fgo_api_v2...: FGO, eCommerce > API > Chei API > Genereaza (shown once)',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'platformUrl',
		'type' => 'text',
		'label' => 'Platform URL',
		'default' => '',
		'transform' => 'trim',
		'help' => 'For issuing, cancelling, reversing, deleting: a site address registered in FGO (Setari > eCommerce); empty: ' . rtrim(\V::app()->createAbsoluteUrl('/'), '/'),
		'validate' => [ [ 'regex', 'pattern' => '^https?://\S+$', 'skipEmpty' => true, 'message' => 'An address, such as https://shop.example.ro' ] ],
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'testEnv',
		'type' => 'switch',
		'label' => 'FGO test environment',
		'default' => false,
		'help' => 'api-testuat.fgo.ro, with an account made at testuat.fgo.ro (not synchronised with production)',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \plugins\RegionalRO\util\Fgo($this, $timeout);
    }

    public function test() {
	try {
	    $id = (array)$this->client(20)->call('GET', 'identitate');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$rights = array_map('strval', (array)($id['drepturi'] ?? []));
	return 'FGO' . ($this->testEnv ? ' (test)' : '') . ': the key works, rights: ' . ($rights ? implode(', ', $rights) : 'none')
	    . (!empty($id['expira_la']) ? '; it expires ' . $id['expira_la'] : '');
    }
}
