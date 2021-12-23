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
		<text id="txt3" x="300" y="765" font-size="15" text-anchor="middle" fill="brown" font-style="italic"><?= $this->site ?></text>
