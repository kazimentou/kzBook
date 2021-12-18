<?php
if (!defined('PLX_ROOT')) {
	exit;
}

?>
		<h3 id="comments"><?php echo $plxShow->artNbCom(); ?></h3>
<?php
while ($plxShow->plxMotor->plxRecord_coms->loop()) { 
	# On boucle sur les commentaires
?>
		<div id="<?php $plxShow->comId(); ?>" class="comment <?php $plxShow->comLevel(); ?>">
			<div id="com-<?php $plxShow->comIndex(); ?>">
				<small>
					<a class="nbcom" href="<?php $plxShow->ComUrl(); ?>" title="#<?php echo $plxShow->plxMotor->plxRecord_coms->i+1 ?>">#<?php echo $plxShow->plxMotor->plxRecord_coms->i+1 ?></a>&nbsp;
					<time datetime="<?php $plxShow->comDate('#num_year(4)-#num_month-#num_day #hour:#minute'); ?>"><?php $plxShow->comDate('#day #num_day #month #num_year(4) - #hour:#minute'); ?></time> -
					<?php $plxShow->comAuthor('link'); ?>
					<?php $plxShow->lang('SAID'); ?> :
				</small>
				<blockquote>
					<p class="content_com type-<?php $plxShow->comType(); ?>"><?php $plxShow->comContent(); ?></p>
				</blockquote>
			</div>
<?php
		if (false and $plxShow->plxMotor->plxRecord_arts->f('allow_com') AND $plxShow->plxMotor->aConf['allow_com']) {
?>
			<a rel="nofollow" href="<?php $plxShow->artUrl(); ?>#form" onclick="replyCom('<?php $plxShow->comIndex() ?>')"><?php $plxShow->lang('REPLY'); ?></a>
<?php
		}
?>
		</div>
<?php 
}
