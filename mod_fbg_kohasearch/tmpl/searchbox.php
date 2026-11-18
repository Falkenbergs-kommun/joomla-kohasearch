<?php
/**
 * @package    FBG Koha Search
 * @subpackage Template
 *
 * Kompakt sökbox med autocomplete. Skickar vidare till sökresultatsidan
 * ($serpUrl) med ?q=sökterm. Riktigt GET-formulär – fungerar utan JavaScript,
 * JS lägger på autocomplete-förslag (kohasearch-box.js).
 */
defined('_JEXEC') or die;
?>
<div class="mod-fbg-kohasearch-box <?php echo $moduleclass_sfx; ?>">

	<?php if (!empty($heading)) : ?>
	<h2 class="fbg-ksb-heading"><?php echo $heading; ?></h2>
	<?php endif; ?>

	<form id="fbg-ksb-form"
		  class="fbg-ksb-form"
		  action="<?php echo htmlspecialchars($serpUrl); ?>"
		  method="get"
		  role="search">
		<div class="fbg-ksb-combo">
			<div class="fbg-ksb-search-row">
				<input type="search"
					   name="q"
					   id="fbg-ksb-input"
					   class="uk-input uk-form-large"
					   placeholder="<?php echo $placeholder; ?>"
					   autocomplete="off"
					   role="combobox"
					   aria-autocomplete="list"
					   aria-expanded="false"
					   aria-controls="fbg-ksb-listbox"
					   aria-label="Sök i bibliotekskatalogen">
				<button type="submit" class="uk-button uk-button-primary uk-button-large" aria-label="Sök">
					<span uk-icon="icon: search; ratio: 1.4" aria-hidden="true"></span>
					<span class="fbg-ksb-btn-text">Sök</span>
				</button>
			</div>
			<ul id="fbg-ksb-listbox" class="fbg-ksb-suggestions" role="listbox" aria-label="Sökförslag" hidden></ul>
		</div>
	</form>

</div>
