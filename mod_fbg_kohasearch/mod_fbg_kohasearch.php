<?php
/**
 * @package    FBG Koha Search
 *
 * @author     Falkenbergs kommun <utveckling@falkenberg.se>
 * @copyright  2026
 * @license    NA
 * @link       falkenberg.se
 */

use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

// Load helper class
require_once __DIR__ . '/helper.php';

// Get module parameters
$moduleclass_sfx   = htmlspecialchars($params->get('moduleclass_sfx', ''));
$placeholder       = htmlspecialchars($params->get('placeholder', 'Sök bland 68 000 titlar...'));
$heading           = htmlspecialchars($params->get('heading', 'Sök i biblioteket'));
$resultsPerPage    = max(1, (int)$params->get('results_per_page', 24));
$imageBaseUrl      = rtrim($params->get('image_base_url', 'https://bibliotek.falkenberg.se/fbg_apps/services/koha/images/'), '/') . '/';
$showFacets        = (int)$params->get('show_facets', 1);
$showAvailability  = (int)$params->get('show_availability', 1);

// Get document object
$document = Factory::getDocument();

// Joomla lagrar layoutvalet med template-prefix: "_:searchbox" (modulens egen
// layout) eller "mallnamn:searchbox" (template-override) – strippa prefixet
$layout     = $params->get('layout', 'default');
$layoutName = ($pos = strrpos($layout, ':')) !== false ? substr($layout, $pos + 1) : $layout;

if ($layoutName === 'searchbox') {
	// Kompakt sökbox med autocomplete – egna lätta assets, ingen fullmodul-JS/CSS
	$serpUrl      = $params->get('serp_url', '/vektor');
	$suggestLimit = max(1, (int)$params->get('suggest_limit', 8));

	$document->addScript('/modules/mod_fbg_kohasearch/assets/js/kohasearch-box.js?v=1');
	$document->addStyleSheet('/modules/mod_fbg_kohasearch/assets/css/kohasearch-box.css?v=4');

	$document->addScriptDeclaration("
	var fbgKohasearchBoxConfig = {
		searchUrl: '/modules/mod_fbg_kohasearch/search.php',
		moduleId: " . (int)$module->id . ",
		suggestLimit: " . $suggestLimit . ",
		minChars: 2,
		debounceMs: 200
	};
");
} else {
	// Fullständig sök- och discovery-modul
	$document->addScript('/modules/mod_fbg_kohasearch/assets/js/kohasearch.js?v=23');
	$document->addStyleSheet('/modules/mod_fbg_kohasearch/assets/css/kohasearch.css?v=13');

	// Live-status i modalen (mode=availability) kräver Koha-uppgifter i
	// integration-koha-web/.env – saknas de skickar JS inte ens anropet
	require_once __DIR__ . '/koha_env.php';
	$liveAvailability = $showAvailability && loadKohaEnv() !== null;

	// Pass configuration to JavaScript
	$document->addScriptDeclaration("
	var fbgKohasearchConfig = {
		searchUrl: '/modules/mod_fbg_kohasearch/search.php',
		ajaxUrl: '" . Uri::root() . "index.php?option=com_ajax&module=fbg_kohasearch&format=json',
		moduleId: " . (int)$module->id . ",
		imageBaseUrl: " . json_encode($imageBaseUrl) . ",
		resultsPerPage: " . $resultsPerPage . ",
		showFacets: " . ($showFacets ? 'true' : 'false') . ",
		showAvailability: " . ($showAvailability ? 'true' : 'false') . ",
		liveAvailability: " . ($liveAvailability ? 'true' : 'false') . "
	};
");
}

// Include template
require ModuleHelper::getLayoutPath('mod_fbg_kohasearch', $params->get('layout', 'default'));
