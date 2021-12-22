<?php
include 'header.php';
?>
<article class="article" id="post-<?php echo $plxShow->artId(); ?>">
	<header>
		<span class="art-date"><time datetime="<?php $plxShow->artDate('#num_year(4)-#num_month-#num_day'); ?>"><?php $plxShow->artDate('#num_day #month #num_year(4)'); ?></time></span>
		<h2><?php $plxShow->artTitle(); ?></h2>
		<div class="small">
			<span class="written-by"><?php $plxShow->lang('WRITTEN_BY'); ?> <?php $plxShow->artAuthor() ?></span>
			<span class="art-nb-com"><a href="#comments" title="<?php $plxShow->artNbCom(); ?>"><?php $plxShow->artNbCom(); ?></a></span>
		</div>
		<div class="small">
<?php
if (!empty($plxShow->plxMotor->mode_extra) and $plxShow->plxMotor->mode_extra == 'all') {
	# On affiche un lien vers les catégories dans la table des matières.

	$result = array();
	foreach ($plxShow->artActiveCatIds() as $catId) {
		if($catId === '000') {
			# article non classé
			$result = array(L_UNCLASSIFIED);
			break;
		}

		if ($catId === 'home') {
			$caption = L_HOMEPAGE;
		} elseif (isset($plxShow->plxMotor->aCats[$catId])) {
			$caption = plxUtils::strCheck($plxShow->plxMotor->aCats[$catId]['name']);
		} else {
			# $catId inconnu
			continue;
		}

		// $result[] = '<a href="../toc.html#' . $catId . '">' . $caption . '</a>';
		$result[] = '<span>' . $caption . '</span>';
	}
?>
			<div class="cats"><span><?php $plxShow->lang('CLASSIFIED_IN') ?>:</span> <?= implode(', ', $result) ?></div>
<?php
}

$taglist = $plxShow->plxMotor->plxRecord_arts->f('tags');
if (!empty($taglist)) {
	$tags = array_map('trim', explode(',', $taglist));
	$result = array();
	foreach ($tags as $idx => $tag) {
		$t = plxUtils::urlify($tag);
		$result[] = '<a href="../tags.html#' . $t . '">' . plxUtils::strCheck($tag) . '</a>';
	}
} else {
	$result = array(L_ARTTAGS_NONE);
}
?>
			<div class="tags"><span><?php $plxShow->lang('TAGS') ?>:</span> <?= implode(', ', $result) ?></div>
		</div>
	</header>
	<?php $plxShow->artThumbnail(); ?>
	<?php $plxShow->artContent(); ?>
</article>
	<?php $plxShow->artAuthorInfos('<div class="author-infos">#art_authorinfos</div>'); ?>
<?php
if ($plxShow->plxMotor->plxRecord_coms) {
	include 'comments.php';
}
?>
<?php
include 'footer.php';
