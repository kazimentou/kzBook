<svg
	 xmlns:dc="http://purl.org/dc/elements/1.1/"
	 xmlns:cc="http://creativecommons.org/ns#"
	 xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
	 xmlns:svg="http://www.w3.org/2000/svg"
	 xmlns="http://www.w3.org/2000/svg"
	 width="600"
	 height="800"
	 viewBox="0 0 600 800"
	 version="1.1">
	<g id="group1">
		<!-- fond de couverture -->
		<rect id="rect1" width="600" height="800" fill="moccasin" />
		<!-- bordure -->
		<path id="path1" fill="none" stroke-width="5" stroke="firebrick"
			d="M60,10 h480 v40 h50 v-40 h-40 v50 h40 v680 h-40 v50 h40 v-40 h-50 v40 h-480 v-40 h-50 v40 h40 v-50 h-40 v-680 h40 v-50 h-40 v40 h 50 z"
			/>
<?php
	/*
	 * $this représente le plugin kzBook
	 *
	 * On peut accèder à $plxMotor
	 * Si $stats est une chaine, alors elle représente l'$id de la catégorie ou est égale à :
	 *   - '000' pour les articles non classés
	 *   - 'home' pour les articles en page d'accueil
	 *   - 'all' pour toutes les catégories
	 * */
	$y = empty($this->subTitle) ? 320 : 250;
?>
		<text id="txt1" x="300" y="<?= $y ?>" font-size="20" text-anchor="middle" fill="brown"><?= $this->author ?></text>
		<text id="txt2" x="300" y="<?= $y + 120 ?>" font-size="50" text-anchor="middle" fill="brown"><?= $this->title ?></text>
<?php
	if (!empty($this->subTitle)) {
?>
		<text id="txt4" x="300" y="<?= $y + 190 ?>" font-size="50" text-anchor="middle" fill="brown"><?= $this->subTitle ?></text>
<?php
	}

	$caption = (is_string($stats) and !empty($plxMotor->aCats[$stats]['description'])) ? $plxMotor->aCats[$stats]['description'] : $plxMotor->aConf['description'];
	if (!empty($caption)) {
?>
		<text id="txt5" x="300" y="650" font-size="20" text-anchor="middle" fill="brown"><?= $caption ?></text>
<?php
	}
?>
		<text id="txt3" x="300" y="775" font-size="15" text-anchor="middle" fill="brown" font-style="italic"><?= $this->site ?></text>
	</g>
</svg>
