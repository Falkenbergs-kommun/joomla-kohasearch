<?php
/**
 * Helper class for FBG Koha Search Module
 *
 * AJAX fallback endpoint via com_ajax.
 *
 * Endpoints:
 *   index.php?option=com_ajax&module=fbg_kohasearch&method=search&format=json
 *   index.php?option=com_ajax&module=fbg_kohasearch&method=facets&format=json
 *   index.php?option=com_ajax&module=fbg_kohasearch&method=browse&format=json
 *
 * @package    FBG Koha Search
 * @subpackage mod_fbg_kohasearch
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

class ModFbgKohasearchHelper
{
	/**
	 * Search via Qdrant vector DB.
	 * AJAX endpoint: &method=search
	 */
	public static function searchAjax()
	{
		try {
			$input = Factory::getApplication()->input;
			$query = trim($input->getString('query', ''));

			if (empty($query)) {
				return ['success' => false, 'message' => 'Sökfrågan kan inte vara tom.'];
			}

			$params = self::getModuleParams();

			$qdrantUrl   = rtrim($params->get('qdrant_url', 'https://qdrant.utvecklingfalkenberg.se'), '/');
			$qdrantKey   = $params->get('qdrant_api_key', '');
			$openaiKey   = $params->get('openai_api_key', '');
			$embModel    = $params->get('embedding_model', 'text-embedding-3-large');
			$imageBaseUrl = rtrim($params->get('image_base_url', 'https://bibliotek.falkenberg.se/fbg_apps/services/koha/images/'), '/') . '/';
			$limit       = min(max(1, $input->getInt('limit', 24)), 100);
			$offset      = max(0, $input->getInt('offset', 0));

			if (empty($qdrantKey) || empty($openaiKey)) {
				throw new \Exception('API-nycklar saknas.');
			}

			// Generera embedding
			$embedding = self::generateEmbedding($query, $openaiKey, $embModel);

			// Sök i Qdrant
			$searchBody = [
				'vector' => ['name' => 'dense', 'vector' => $embedding],
				'limit'  => $limit,
				'offset' => $offset,
				'with_payload' => true,
				'with_vector'  => false,
			];

			$resp = self::makeRequest(
				"{$qdrantUrl}/collections/koha-biblios/points/search",
				'POST',
				$searchBody,
				['api-key: ' . $qdrantKey, 'Content-Type: application/json']
			);

			$data   = json_decode($resp, true);
			$points = $data['result'] ?? [];

			$results = [];
			foreach ($points as $point) {
				$p = $point['payload'] ?? [];
				$isbn = $p['isbn'] ?? '';
				$isbnClean = preg_replace('/[^0-9X]/', '', strtoupper(explode(',', str_replace('|', ',', $isbn))[0] ?? ''));

				$results[] = [
					'biblio_id'       => $p['biblio_id'] ?? null,
					'title'           => $p['title'] ?? 'Okänd titel',
					'author'          => $p['author'] ?? '',
					'publication_year' => $p['publication_year'] ?? null,
					'media_types'     => $p['media_types'] ?? [],
					'image_url'       => !empty($isbnClean) ? $imageBaseUrl . $isbnClean . '.jpg' : null,
					'catalog_link'    => $p['catalog_link'] ?? '',
					'available_items' => $p['available_items'] ?? 0,
					'available_branches' => $p['available_branches'] ?? [],
					'on_order_items'  => $p['on_order_items'] ?? 0,
					'total_items'     => $p['total_items'] ?? 0,
					'score'           => round($point['score'] ?? 0, 4),
				];
			}

			return [
				'success'     => true,
				'results'     => $results,
				'total_found' => count($results) < $limit ? $offset + count($results) : $offset + $limit + 1,
				'query'       => $query,
			];

		} catch (\Exception $e) {
			// Detaljerna (API-svar, interna adresser) loggas men skickas inte till klienten
			error_log('ModFbgKohasearch searchAjax error: ' . $e->getMessage());
			return ['success' => false, 'message' => 'Sökningen kunde inte genomföras. Försök igen om en stund.'];
		}
	}

	/**
	 * Get facets from Directus.
	 * AJAX endpoint: &method=facets
	 */
	public static function facetsAjax()
	{
		// Delegera till search.php via intern include (enklare)
		try {
			$params       = self::getModuleParams();
			$directusUrl  = rtrim($params->get('directus_url', ''), '/');
			$directusToken = $params->get('directus_token', '');

			if (empty($directusUrl) || empty($directusToken)) {
				throw new \Exception('Directus-konfiguration saknas.');
			}

			// Enkel version: returnera bara tomma facetter vid com_ajax
			return ['success' => true, 'facets' => [], 'message' => 'Använd search.php direkt för facetter.'];

		} catch (\Exception $e) {
			error_log('ModFbgKohasearch facetsAjax error: ' . $e->getMessage());
			return ['success' => false, 'message' => 'Facetterna kunde inte hämtas.'];
		}
	}

	/**
	 * Browse endpoint.
	 * AJAX endpoint: &method=browse
	 */
	public static function browseAjax()
	{
		return ['success' => true, 'books' => [], 'message' => 'Använd search.php direkt för browse.'];
	}

	// ── Internals ────────────────────────────────────────────────────

	private static function getModuleParams(): Registry
	{
		$db    = Factory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('params'))
			->from($db->quoteName('#__modules'))
			->where($db->quoteName('module') . ' = ' . $db->quote('mod_fbg_kohasearch'))
			->where($db->quoteName('published') . ' = 1');

		$db->setQuery($query);
		$paramsJson = $db->loadResult();

		return new Registry($paramsJson ?: '{}');
	}

	private static function generateEmbedding(string $text, string $apiKey, string $model): array
	{
		$data = ['model' => $model, 'input' => $text, 'encoding_format' => 'float'];
		$resp = self::makeRequest(
			'https://api.openai.com/v1/embeddings',
			'POST',
			$data,
			['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']
		);

		$result = json_decode($resp, true);
		if (!isset($result['data'][0]['embedding'])) {
			throw new \Exception('Ogiltigt svar från OpenAI Embeddings API.');
		}

		return $result['data'][0]['embedding'];
	}

	private static function makeRequest(string $url, string $method = 'GET', $data = null, array $headers = []): string
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_TIMEOUT        => 60,
		]);

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			if ($data !== null) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			}
		}

		if (!empty($headers)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error    = curl_error($ch);
		curl_close($ch);

		if ($error) {
			throw new \Exception("cURL-fel: {$error}");
		}

		if ($httpCode < 200 || $httpCode >= 300) {
			throw new \Exception("HTTP-fel {$httpCode}: " . substr($response, 0, 200));
		}

		return $response;
	}
}
