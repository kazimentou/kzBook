<?php
include 'header.php'; 

$catsPattern = '<a href="cats.html##tag_name" title="#tag_name">#tag_name</a>';
$tagsPattern = '<a href="../tags.html##tag_name" title="#tag_name">#tag_name</a>';
?>
	<main class="main">
		<div class="container">
			<div class="grid">
				<div class="content col sml-12 med-9">
					<article class="article" id="post-<?php echo $plxShow->artId(); ?>">
						<header>
							<span class="art-date"><time datetime="<?php $plxShow->artDate('#num_year(4)-#num_month-#num_day'); ?>"><?php $plxShow->artDate('#num_day #month #num_year(4)'); ?></time></span>
							<h2><?php $plxShow->artTitle(); ?></h2>
							<div>
								<small>
									<span class="written-by"><?php $plxShow->lang('WRITTEN_BY'); ?> <?php $plxShow->artAuthor() ?></span>
									<span class="art-nb-com"><a href="#comments" title="<?php $plxShow->artNbCom(); ?>"><?php $plxShow->artNbCom(); ?></a></span>
								</small>
							</div>
							<div>
								<small>
<?php
if (!empty($plxShow->plxMotor->mode_extra) and $plxShow->plxMotor->mode_extra != 'cat') {
?>
									<span class="classified-in"><?php $plxShow->lang('CLASSIFIED_IN') ?> : <?php $plxShow->artCat($catsPattern) ?></span>
<?php	
}
?>
									<span class="tags"><?php $plxShow->lang('TAGS') ?> : <?php $plxShow->artTags($tagsPattern) ?></span>
								</small>
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
				</div>
			</div>
		</div>
	</main>
<?php include 'footer.php'; ?>
