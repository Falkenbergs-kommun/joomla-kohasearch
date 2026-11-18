<?php
/**
 * @package    FBG Koha Search
 * @subpackage Template
 */
defined('_JEXEC') or die;
?>
<div class="mod-fbg-kohasearch <?php echo $moduleclass_sfx; ?>">

	<?php if (!empty($heading)) : ?>
	<h2 class="fbg-ks-heading"><?php echo $heading; ?></h2>
	<?php endif; ?>

	<!-- Sökfält -->
	<div class="fbg-ks-search-wrapper" role="search">
		<div class="fbg-ks-search-row">
			<input type="search"
				   id="fbg-ks-input"
				   class="uk-input uk-form-large"
				   placeholder="<?php echo $placeholder; ?>"
				   autocomplete="off"
				   aria-label="Sök i bibliotekskatalogen">
			<button id="fbg-ks-submit" class="uk-button uk-button-primary uk-button-large" aria-label="Sök">
				<span uk-icon="icon: search"></span>
				<span class="fbg-ks-btn-text">Sök</span>
			</button>
		</div>
		<div id="fbg-ks-active-filters" class="fbg-ks-active-filters uk-hidden" role="region" aria-label="Aktiva filter"></div>
	</div>

	<!-- Browse-läge (startsida) -->
	<div id="fbg-ks-browse" class="uk-margin-top">
		<section id="fbg-ks-browse-latest" class="uk-margin-medium-bottom">
			<h3 class="fbg-ks-section-title">Senaste nyförvärv</h3>
			<div class="fbg-ks-browse-slider" data-section="latest">
				<div class="uk-text-center uk-padding-small" role="status" aria-label="Laddar nyförvärv">
					<div uk-spinner="ratio: 1.5"></div>
				</div>
			</div>
		</section>
		<section id="fbg-ks-browse-popular" class="uk-margin-medium-bottom">
			<h3 class="fbg-ks-section-title">Populärast just nu</h3>
			<div class="fbg-ks-browse-slider" data-section="popular">
				<div class="uk-text-center uk-padding-small" role="status" aria-label="Laddar populära böcker">
					<div uk-spinner="ratio: 1.5"></div>
				</div>
			</div>
		</section>
	</div>

	<!-- Sökresultat (dolt initialt) -->
	<div id="fbg-ks-search-content" class="uk-hidden uk-margin-top">
		<div uk-grid class="uk-grid-medium">

			<?php if ($showFacets) : ?>
			<!-- Filtreringsknapp (mobil) -->
			<div class="uk-width-1-1 uk-hidden@m">
				<button id="fbg-ks-filter-toggle" class="fbg-ks-filter-toggle" aria-expanded="false" aria-controls="fbg-ks-facets-panel">
					<span uk-icon="icon: settings; ratio: 0.85"></span> Filter
					<span id="fbg-ks-filter-count-badge" class="fbg-ks-filter-count-badge uk-hidden">0</span>
				</button>
			</div>
			<!-- Facetter -->
			<div id="fbg-ks-facets-panel" class="uk-width-1-4@m uk-width-1-1 fbg-ks-facets-collapsible">
				<div class="fbg-ks-facets">
					<div class="uk-flex uk-flex-between uk-flex-middle uk-margin-small-bottom">
						<h4 class="uk-margin-remove">Filter</h4>
						<button id="fbg-ks-clear-filters" class="uk-button uk-button-text uk-text-small uk-hidden">Rensa</button>
					</div>
					<div id="fbg-ks-facets-content">
						<div class="uk-text-center uk-padding-small" role="status" aria-label="Laddar filter">
							<div uk-spinner="ratio: 1"></div>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<!-- Resultat -->
			<div class="uk-width-expand@m">
				<!-- Resultatrubrik -->
				<div id="fbg-ks-result-header" class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom uk-hidden">
					<div>
						<span id="fbg-ks-result-count" class="uk-text-muted"></span>
						<button id="fbg-ks-back-browse" class="uk-button uk-button-text uk-margin-small-left">
							<span uk-icon="icon: arrow-left; ratio: 0.8"></span> Tillbaka
						</button>
					</div>
					<div class="fbg-ks-sort-wrapper">
						<label for="fbg-ks-sort" class="fbg-ks-sort-label">Sortera:</label>
						<select id="fbg-ks-sort" class="fbg-ks-sort-select">
							<option value="relevance_desc">Relevans</option>
							<option value="year_desc">Utgivningsår ↓</option>
							<option value="year_asc">Utgivningsår ↑</option>
							<option value="added_desc">Nyast i katalogen</option>
							<option value="added_asc">Äldst i katalogen</option>
							<option value="author_asc">Författare A–Ö</option>
							<option value="author_desc">Författare Ö–A</option>
							<option value="title_asc">Titel A–Ö</option>
							<option value="title_desc">Titel Ö–A</option>
						</select>
					</div>
				</div>

				<!-- Resultatgrid -->
				<div id="fbg-ks-results" aria-live="polite"></div>

				<!-- Paginering -->
				<div id="fbg-ks-pagination" class="uk-margin-top uk-flex uk-flex-center"></div>
			</div>
		</div>
	</div>

	<!-- Laddning -->
	<div id="fbg-ks-loading" class="uk-text-center uk-padding uk-hidden" role="status">
		<div uk-spinner="ratio: 2"></div>
		<p class="uk-margin-small-top uk-text-muted">Söker i bibliotekskatalogen...</p>
	</div>

	<!-- Fel -->
	<div id="fbg-ks-error" class="uk-hidden uk-margin-top">
		<div class="uk-alert-danger" uk-alert role="alert">
			<a class="uk-alert-close" uk-close aria-label="Stäng felmeddelande"></a>
			<p><span uk-icon="icon: warning"></span> <span id="fbg-ks-error-text"></span></p>
		</div>
	</div>

	<!-- Bokmodal -->
	<div id="fbg-ks-modal" uk-modal>
		<div class="uk-modal-dialog uk-modal-body fbg-ks-modal-dialog">
			<button class="uk-modal-close-default" type="button" uk-close aria-label="Stäng"></button>
			<div id="fbg-ks-modal-loading" class="uk-text-center uk-padding" role="status" aria-label="Laddar bokdetaljer">
				<div uk-spinner="ratio: 1.5"></div>
			</div>
			<div id="fbg-ks-modal-content" class="uk-hidden"></div>
		</div>
	</div>

</div>
