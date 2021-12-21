<?php
include 'header.php';
?>
	<main class="main">
		<h1>Index</h1>
<?php
if (isset($plxShow->plxMotor->kzTags)) {
?>
		<ul>
<?php
	foreach($plxShow->plxMotor->kzTags as $tag=>$articles) {
?>
			<li>
				<h2><a id="<?= $tag ?>"><?= ucFirst($tag) ?></a></h2>
				<ul>
<?php
		foreach($articles as $art) {
?>
					<li><a href="<?= $art['href'] ?>"><?= $art['title'] ?></a></li>
<?php
		}
?>
				</ul>
			</li>
<?php
	}
?>
		</ul>
<?php
}
?>
	</main>
<?php
include 'footer.php';
