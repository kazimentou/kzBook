<?php include 'header.php'; ?>
	<main class="main">
		<div class="container">
			<div class="grid">
				<div class="content">
					<article class="article static">
						<h2><?php $plxShow->staticTitle(); ?></h2>
						<?php $plxShow->staticContent(); ?>
					</article>
				</div>
			</div>
		</div>
	</main>
<?php
include 'footer.php';
