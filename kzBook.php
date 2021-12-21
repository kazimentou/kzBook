<?php

if (!defined('PLX_ROOT')) {
	exit;
}

class kzBook extends plxPlugin {
	const HOOKS = array(
		'plxShowStaticListEnd',
		'plxMotorPreChauffageBegin',
	);

	# Ne pas inclure l'entête XML dans un script PHP (Erreur de syntaxe sinon).
	const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;

	const BEGIN_CODE = '<?php' . PHP_EOL;
	const END_CODE = PHP_EOL . '?>';

	const CONTAINER = <<< EOT
<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">
	<rootfiles>
		<rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml" />
	</rootfiles>
</container>
EOT;
	const IMG_COVER_NAME = 'cover.svg';
	const IMG_PATTERN = '#\.(?:jpe?g|gif|png|bmp|svg)$#i';
	const STATIC_TEMPLATE = 'static.php';

	private $_folder = false; # Dossier pour stocker les ebooks construits et les images de couverture
	private $_style = false;
	private $_bookName = 'foo';
	private $_filename = false;
	private $_pattern = false;

	function __construct($default_lang) {
		parent::__construct($default_lang);
		if (defined('PLX_ADMIN')) {
			parent::setConfigProfil(PROFIL_ADMIN);
		}
		foreach(self::HOOKS as $hk) {
			$this->addHook($hk, $hk);
		}
	}

	private function _updateLinks($content) {
		return preg_replace_callback(
			$this->_pattern,
			function($matches) {
				# $this est l'instance de kzBook
				$this->medias[] = $matches[2];
				return $matches[1] . '="medias/' . $matches[2];
			},
			$content
		);
	}

	private function _buildStatic($template) {

		$template = preg_replace('#-full-width\.php$#', '.php', $template);
		$inc = $this->_style . $template;

		# On emploie le template par défaut si $template n'existe pas.
		if ($template != self::STATIC_TEMPLATE and !file_exists($inc)) {
			$template = self::STATIC_TEMPLATE;
			$inc = $this->_style . $template;
		}

		if (!file_exists($inc)) {
			return false;
		}

		# Pré-requis pour le script $inc.
		$plxShow = plxShow::getInstance();
		$plxMotor = $plxShow->plxMotor;
		$plxMotor->template = $template; # Pas vraiement utils

		# Génération du contenu avec l'include.
		ob_start();
		include $inc;
		$content = ob_get_clean();

		# On ajuste les urls pour les liens et les images.
		return kzBook::XML_HEADER . $this->_updateLinks($content);
	}

	private function _buildArt() {
		$plxShow = plxShow::getInstance();
		$plxMotor = $plxShow->plxMotor;

		$inc = $this->_style . $plxMotor->plxRecord_arts->f('template');
		if (!file_exists($inc)) {
			$inc = $this->_style . 'article.php';
			if (!file_exists($inc)) {
				return false;
			}
		}

		$plxMotor->getCommentaires('#^'.$plxMotor->cible.'.\d{10}-\d+.xml$#' , $plxMotor->tri_coms);

		# Génération du contenu avec l'include.
		ob_start();
		include $inc;
		$content = kzBook::XML_HEADER . ob_get_clean();

		# On ajuste les urls pour les liens et les images.
		return $this->_updateLinks($content);
	}

	private function _buildArtsIndex() {
		$inc = $this->_style . 'tags-list.php';
		if (!file_exists($inc)) {
			return false;
		}

		# Pré-requis pour le script $inc.
		$plxShow = plxShow::getInstance();
		$plxMotor = $plxShow->plxMotor;

		# Génération du contenu avec l'include.
		ob_start();
		include $inc;
		return kzBook::XML_HEADER . ob_get_clean();
	}

	private function _buildCover($href) {
		$plxShow = plxShow::getInstance();
		$plxMotor = $plxShow->plxMotor;
		$plxMotor->mode = 'cover';
		$plxShow->imgCover = $href;

		$inc = $this->_style . 'cover.php';
		if (!file_exists($inc)) {
			return false;
		}

		# Génération du contenu avec l'include.
		ob_start();
		include $inc;
		return kzBook::XML_HEADER . ob_get_clean();
	}

	/*
	 * Construction de l'ebook (archive zip).
	 * $stats est : soit un tableau de pages statiques sélectionnées, soit l'indice d'une catégorie.
	 * */
	private function _build($stats) {
		$plxMotor = plxMotor::getInstance();
		if (file_exists($this->_filename)) {
			# On supprime l'ancien ebook correspondant
			unlink($this->_filename);
		}

		try {
			$zip = new ZipArchive;
			if ($zip->open($this->_filename, ZipArchive::CREATE) === true) {
				$zip->addFromString('mimetype', 'application/epub+zip');
				$zip->addFromString('META-INF/container.xml', self::XML_HEADER . self::CONTAINER);
				$manifest = Array();
				$spine = Array();
				$toc = Array();
				$this->medias = Array();

				# Page de couverture du livre
				if (empty($this->_imgCover)) {
					# On crée une image par défaut pour la couverture
					$content = $this->_makeDefaultCover();
					$href = 'text/' . self::IMG_COVER_NAME;
					$zip->addFromString('OEBPS/' . $href, $content);
				} else {
					$href = 'text/cover.' . pathinfo($this->_imgCover,  PATHINFO_EXTENSION);
					$zip->addFile($this->_imgCover, 'OEBPS/' . $href);
				}
				$content = $this->_buildCover(basename($href));
				if(!empty($content)) {
					$href = 'text/cover.html';
					$idx = 'cover-img';
					$zip->addFromString('OEBPS/' . $href, $content);
					$manifest[$idx] = $href;
					$spine[] = $idx;
				}

				if (is_array($stats)) {
					# Par défaut, on traite les pages statiques recensées dans $stats
					$plxMotor->mode = 'static';
					foreach($stats as $k=>$v) {
						# On mime plxShow::prechauffage()
						$plxMotor->get = 'static' . $k . '/' . $v['url'];
						$plxMotor->cible = $k;

						// $template = preg_replace('#-full-width\.php$#', '.php', $v['template']);
						// $plxMotor->template = ($template != 'static.php' and file_exists($this->_style . $template)) ? $template : 'static.php';

						# Aucune action dans plxMotor::demarrage() pour le mode 'static'. Peut-être un plugin ?
						# $plxMotor->demarrage();

						$content = $this->_buildStatic($v['template']);
						if (!empty($content)) {
							# On évite d'archiver un contenu vide ( absence de template ).
							$href = 'text/stat-' . $k . '.html';
							$zip->addFromString('OEBPS/' . $href, $content);
							$idx = 'stat-' . $k;
							$manifest[$idx] = $href;
							$spine[] = $idx;

							# On actualise la table des matières
							$toc[$href] = $v['name'];
						}
					}
				} elseif (in_array($stats, Array('arts', 'home'))) {

					# ============= On traite une sélection d'articles ==============

					# Pas de nouveau commentaire
					$plxMotor->aConf['allow_com'] = 0;

					$plxMotor->mode = 'article';
					$tagsList = Array();
					while ($plxMotor->plxRecord_arts->loop()) {
						$artId = $plxMotor->plxRecord_arts->f('numero');
						$plxMotor->cible = $artId; # nécessaire pour lister les commentaires
						$content = $this->_buildArt();

						if (!empty($content)) {
							# On évite d'archiver un contenu vide ( absence de template ).
							$k = 'art-' . $artId;
							$href = 'text/' . $k . '.html';
							# On enregistre l'article dans l'archive zip
							$zip->addFromString('OEBPS/' . $href, $content);
							$manifest[$k] = $href;
							$spine[] = $k;

							# On actualise la table des matières
							$artTitle = $plxMotor->plxRecord_arts->f('title');
							if ($stats != 'home') {
								$toc[$href] = $artTitle;
							} else {
								foreach(explode(',', $plxMotor->plxRecord_arts->f('categorie')) as $cat) {
									if (!array_key_exists($cat, $toc)) {
										$toc[$cat] = array();
									}
									$toc[$cat][$href] = $artTitle;
								}
							}

							# On récupère les tags de chaque article
							if ($tags = $plxMotor->plxRecord_arts->f('tags')) {
								foreach(array_map('trim', explode(',', $tags)) as $t) {
									# On stocke l'inforlation de l'article
									$value = Array(
										'href'	=> $href,
										'title'	=> $plxMotor->plxRecord_arts->f('title'),
									);
									if (!array_key_exists($t, $tagsList)) {
										$tagsList[$t] = Array($value);
									} else {
										$tagsList[$t][] = $value;
									}
								}
							}
						}
					}

					if($stats == 'home') {
						# Plusieurs catégories à trier
						uksort($toc, function($key1, $key2) {
							if ($key1 == 'home') {
								return -1;
							}
							if ($key2 == 'home') {
								return 1;
							}
							if ($key1 == '000') {
								return 1;
							}
							if ($key2 == '000') {
								return -1;
							}
							return ($key1-$key2);
						});
					}

					if (!empty($tagsList)) {
						# On génére  une page index
						ksort($tagsList);
						$plxMotor->kzTags = $tagsList;
						$content = $this->_buildArtsIndex();
						if (!empty($content)) {
							$idx = 'tags';
							$href = $idx . '.html';
							$zip->addFromString('OEBPS/' . $href, $content);
							$manifest[$idx] = $href;
							$spine[] = $idx;
							$toc[$href] = $this->getLang('INDEX');
						}
					}
				}

				if (!empty($toc)) {
					# Génération de la table des matières
					ob_start();
?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1" xml:lang="fr">
  <head>
    <meta name="dtb:uid" content="b969f5a8-8c67-46b5-aa69-09a820861701"/>
    <meta name="dtb:depth" content="2"/>
    <meta name="dtb:generator" content="<?= __CLASS__ ?> plugin for PluXml"/>
    <meta name="dtb:totalPageCount" content="0"/>
    <meta name="dtb:maxPageNumber" content="0"/>
  </head>
  <docTitle>
    <text>Premiers tests</text>
  </docTitle>
  <navMap>
<?php
					$count = 0;
					$subCount = count($toc);
					foreach($toc as $k=>$v) {
						$count++;
						if (is_array($v)) {
							# Toutes les catégories d'articles
							# $k est l'id de la catégorie
							if (isset($plxMotor->aCats[$k])) {
								$caption = $plxMotor->aCats[$k]['name'];
							} else {
								$caption = $this->getLang(($k == 'home') ? 'CATEGORY_HOME_PAGE' : 'UNCLASSIFIED_ARTS');
							}
							$href = array_key_first($v);
						} else {
							# Une seule catégorie dans le livre électronique
							$href = $k;
							$caption = $v;
						}
?>
	<navPoint id="num_<?= $count ?>" playOrder="<?= $count ?>">
		<navLabel>
			<text><?= $caption ?></text>
		</navLabel>
		<content src="<?= $href ?>"/>
<?php
						if (is_array($v)) {
							foreach($v as $subHref=>$subCaption) {
								$subCount++;
?>
		<navPoint id="num_<?= $subCount ?>" playOrder="<?= $subCount ?>">
			<navLabel>
				<text><?= $subCaption ?></text>
			</navLabel>
			<content src="<?= $subHref ?>"/>
		</navPoint>
<?php
							}
						}
?>
	</navPoint>
<?php
					}
?>
  </navMap>
</ncx>
<?php
					$hrefToc = 'toc.ncx';
					$zip->addFromString('OEBPS/' . $hrefToc, self::XML_HEADER . ob_get_clean());
				} # !$empty($toc)

				# Génération du contenu de content.opf
				ob_start();
				/*
	Dans <metadata /> :
		<dc:identifier id="ean">9782335005110</dc:identifier>
		<meta name="cover" content="img_Cover"/>
				 * */

?>
<package xmlns:opf="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns="http://www.idpf.org/2007/opf" xmlns:ox="http://www.editeur.org/onix/2.1/reference/" version="2.0" unique-identifier="ean">
	<metadata>
		<dc:title>Les pages de <?= $plxMotor->aConf['title']; ?></dc:title>
		<dc:publisher><?= $plxMotor->racine ?></dc:publisher>
		<dc:creator><?= $this->author ?></dc:creator>
		<dc:rights>Copyright © <?= date('Y') ?></dc:rights>
		<dc:language><?= $plxMotor->aConf['default_lang']; ?></dc:language>
		<dc:date><?= date('Y-m-d') ?></dc:date>
		<dc:format>epub</dc:format>
	</metadata>
	<manifest>
<?php
				# On recense tous les fichiers dans l'archive avec leurs mimetype
				# pour les pages html
				foreach($manifest as $id=>$href) {
?>
		<item id="<?= $id ?>" href="<?= $href ?>" media-type="application/xhtml+xml"/>
<?php
				}
				foreach(array_unique($this->medias) as $i=>$media) {
					$id = str_pad($i, 3, '0', STR_PAD_LEFT);
					$href = 'text/medias/' . $media;
					$mimetype = 'image/jpeg';
					$path = PLX_ROOT . $plxMotor->aConf['medias'] . $media;
					if (file_exists($path)) {
						$size = getimagesize($path);
						if (!empty($size)) {
							$mimetype = $size[2];
						}
					}
					# ajoute le media dans l'archive zip
					if ($zip->addFile($path, 'OEBPS/' . $href)) {
?>
		<item id="media-<?= $id ?>" href="<?= $href ?>" media-type="<?= $mimetype ?>" />
<?php
					}
				}

				# On ajoute les feuilles de style et les fonts du thème
				$offset = strlen($this->_style);
				foreach(glob($this->_style . 'css/*.css') as $path) {
					$id = basename($path, '.css');
					$href = 'text/' . substr($path, $offset);
					if ($zip->addFile($path, 'OEBPS/' . $href)) {
?>
		<item id="css-<?= $id ?>" href="<?= $href ?>" media-type="text/css" />
<?php
					}
				}

				if (!empty($hrefToc)) {
?>
		<item href="<?= $hrefToc ?>" id="ncx" media-type="application/x-dtbncx+xml"/>
<?php
				}
?>
	</manifest>
	<spine toc="ncx">
<?php
				foreach($spine as $t) {
?>
		<itemref idref="<?= $t ?>" />
<?php
				}
?>
	</spine>
</package>
<?php
				$zip->addFromString('OEBPS/content.opf', self::XML_HEADER . ob_get_clean());
				$zip->close();
			} else {
				plxMsg::Error('Impossible de créer l\'archive ' . $filename);
			}
		} catch (Exception $e) {
			plxMsg::Error('Exception: ' . $e->getMessage());
		}
	}

	/*
	 * On vérifie s'il existe une ou des images pour la couverture.
	 * */
	private function _getImgCover($scope, $value) {
		$pattern = $this->_folder . '/covers/' . $scope . '/' . $value . '.*';
		$files = glob($pattern);

		if(count($files) === 0) {
			return false;
		}

		if (count($files) > 1) {
			# On trie les images par date décroissante
			usort($files, function ($filename1, $filename2) {
				return (filemtime($filename2) - filemtime($filename1));
			});
		}
		foreach ($files as $f) {
			# On vérifie l'extension du fichier pour une image
			if (preg_match(self::IMG_PATTERN, $f)) {
				$this->_imgCover = $f;
				return true;
				break;
			}
		}

		return false;
	}

	/*
	 * Génère et télécharge le livre électronique au format epub.
	 * */
	public function sendEpub($scope, $value) {
		# la Class plxShow existe !
		$plxShow = plxShow::getInstance();
		$plxMotor = $plxShow->plxMotor;

		# Nouvlle propriété pour $plxMotor
		$plxMotor->mode_extra = $scope;

		$this->_folder = PLX_ROOT . $plxMotor->aConf['medias'] . __CLASS__;
		if (!is_dir($this->_folder) and !mkdir($this->_folder)) {
			return false;
		}

		$folder = $this->_folder . '/covers';
		if (is_dir($folder) or mkdir($folder)) {
			foreach(Array('/stat', '/group', '/template', '/cat', '/home') as $f) {
				$g = $folder . $f;
				if(!is_dir($g) and !mkdir($g)) {
					break;
				}
			}
		}

		$str = $value;
		if ($scope == 'cat') {
			if (preg_match('#^\d{1,3}$#', $value)) {
				$k = str_pad($value, 3, '0', STR_PAD_LEFT);
				$str = (isset($plxMotor->aCats[$k]['name'])) ? $plxMotor->aCats[$k]['name'] : $this->getLang('UNCLASSIFIED_ARTS');
			} else {
				$str = $this->getLang(($value == 'home') ? 'Page d\'accueil' : 'UNCLASSIFIED_ARTS');
			}
		}
		$this->_bookName = 'pluxml-' . $scope . '-' . preg_replace('#[^\w-]+#', '_', strtolower($str)) . '.epub';
		$this->_filename = $this->_folder . '/' . $this->_bookName;
		$this->_pattern = '#\b(href|src)="(?:' . $plxMotor->racine . ')?'. $plxMotor->aConf['medias'] . '([^"]*)#';


		$style = $this->getParam('style');
		if (empty($style)) {
			$style = 'default';
		}
		$this->_style = PLX_PLUGINS . __CLASS__ . '/themes/' . $style . '/';
		$plxMotor->mode = (in_array($scope, array('stat', 'group', 'template'))) ? 'static' : 'article';
		$this->title = '';
		$this->author = $plxMotor->aUsers['001']['name'];
		$this->site = $plxMotor->aConf['racine'];

		switch ($scope) {
			case 'group':
			case 'template':
			case 'stat':
				if ($scope != 'stat') {
					$stats = array_filter($plxMotor->aStats, function($aStat) use($scope, $value) {
						# On éjecte les pages statiques inactives.
						if (empty($aStat['active'])) {
							return false;
						}

						return (
							($scope == 'group' and $value == $aStat['group']) or
							($scope == 'template' and $value == basename($aStat['template'], '.php'))
						);
					});
				} else {
					# $value n'est pas pris en compte. On prend toutes les pages statiques.
					$stats = $plxMotor->aStats;
				}
				switch($scope) {
					case 'group': $this->title = sprintf($this->getLang('GROUP_PATTERN'), ucfirst($value)); break;
					case 'template': $this->title = sprintf($this->getLang('TEMPLATE_PATTERN'), ucfirst($value)); break;
					default: $this->title = $this->getLang('PAGES');
				}
				$this->_getImgCover($scope, $value);
				self::_build($stats);
				break;
			case 'home':
				$this->title = $this->getLang('ARTICLES');

				# Configuration pour plxShow::demarrage()
				$plxMotor->mode = 'home';
				$plxMotor->cible = '';
				$plxMotor->motif = '#^\d{4}\.(?:\d{3}|home)+(?:,\d{3}|,home)*\.\d{3}\.\d{12}\.[\w-]+\.xml$#';
				$plxMotor->tri = 'asc';
				$plxMotor->bypage = false; # unlimited !
				$plxMotor->demarrage();

				$this->_getImgCover($scope, $value);
				$this->_build('home');
				break;
			case 'cat':
				# On mime plxShow::prechauffage().
				switch($value) {
					case '000' :
					case 'home':
						$idCat = $value;
						$this->title = $this->getLang(($value == 'home') ? 'CATEGORY_HOME_PAGE' : 'UNCLASSIFIED_ARTS');
						$plxMotor->cible = $value;
						$subPattern = ($value == 'home') ? '(?:\d{3},)*home(?:,\d{3})' : '000';
						$plxMotor->template = 'article.php';
						$plxMotor->tri = $plxMotor->aConf['tri'];
						break;
					default:
						$idCat = str_pad($value, 3, '0', STR_PAD_LEFT);
						if (empty($plxMotor->aCats[$idCat]['active'])) {
							return false;
						} else {
							$this->title = sprintf($this->getLang('CATEGORY_PATTERN'), $plxMotor->aCats[$idCat]['name']);
						}
						$plxMotor->cible = $idCat;
						$subPattern = '(?:\D{3},|HOME,)*' . $idCat . '(?:,home|,\d{3})*';
						$cat = $plxMotor->aCats[$idCat];
						$plxMotor->template = $cat['template'];
						# Recuperation du tri des articles
						$plxMotor->tri = $cat['tri'];
				}


				$plxMotor->mode = 'categorie';
				$plxMotor->bypage = false; # unlimited !
				# Motif de recherche des articles
				$plxMotor->motif = '#^\d{4}\.' . $subPattern . '\.\d{3}\.\d{12}\.[\w-]+\.xml$#';
				# Recherche les articles pour la sélection
				$plxMotor->demarrage();

				$this->_getImgCover($scope, $value);
				$this->_build('arts');
				break;
			default:
				# Contexte inconnu. Bye !
				return false;
		}
		if (!empty($this->_filename) and file_exists($this->_filename)) {
			header('Content-Description: File Transfer');
			header('Content-Type: application/epub+zip');
			header('Content-Disposition: attachment; filename=' . $this->_bookName);
			header('Content-Transfer-Encoding: binary');
			header('Expires: 0');
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			header('Pragma: no-cache');
			header('Content-Length: ' . filesize($this->_filename));
			readfile($this->_filename);

			# success !!
			return true;
		}

		return false;
	}

	public function listing() {
		$result = array(
			'stat/all'	=> $this->getLang('ALL_STATS'),
		);

		# groupes des pages statiques
		$plxMotor = plxMotor::getInstance();
		$groups = array_unique(
			array_map(
				function($value) {
					return $value['group'];
				},
				array_values(
					array_filter(
						$plxMotor->aStats,
						function($value) {
							return (!empty($value['active']) and !empty($value['group']));
						}
					)
				)
			)
		);
		$pattern = $this->getLang('GROUP_PATTERN');
		foreach($groups as $gr) {
			$result['group/' . urlencode($gr)] = sprintf($pattern, $gr);
		}


		# Gabarits des pages statiques
		$templates = array_unique(array_values(
			array_map(
				function($value) {
					return basename($value['template'], '.php');
				},
				array_filter(
					$plxMotor->aStats,
					function ($value) {
						return !empty($value['active']);
					}
				)
			)
		));
		$pattern = $this->getLang('TEMPLATE_PATTERN');
		foreach($templates as $tp) {
			$result['template/' . $tp] = sprintf($pattern, $tp);
		}

		# Catégories
		$cats = array_map(
			function($value) {
				return $value['name'];
			},
			array_filter(
				$plxMotor->aCats,
				function($value) {
					return  (!empty($value['active']) and $value['articles'] > 0);
				}
			)
		);
		asort($cats);
		$pattern = $this->getLang('CATEGORY_PATTERN');
		foreach($cats as $id=>$name) {
			$result['cat/' . $id] = sprintf($pattern, $name);
		}

		# AUtres choix pour les articles
		$result['cat/home'] = $this->getLang('HOMEPAGE_ARTS');
		$result['cat/000'] = $this->getLang('UNCLASSIFIED_ARTS');
		$result['home/all'] = $this->getLang('ALL_ARTICLES');

		return $result;
	}

	private function _makeDefaultCover() {
		ob_start();
?>
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
		<rect id="rect1" width="600" height="800" fill="moccasin" />
		<path id="path1" fill="none" stroke-width="5" stroke="firebrick"
			d="M60,10 h480 v40 h50 v-40 h-40 v50 h40 v680 h-40 v50 h40 v-40 h-50 v40 h-480 v-40 h-50 v40 h40 v-50 h-40 v-680 h40 v-50 h-40 v40 h 50 z"
			/>
		<text id="txt1" x="300" y="350" font-size="20" text-anchor="middle" fill="brown"><?= $this->author ?></text>
		<text id="txt2" x="300" y="420" font-size="50" text-anchor="middle" fill="brown"><?= $this->title ?></text>
		<text id="txt3" x="300" y="775" font-size="15" text-anchor="middle" fill="brown" font-style="italic"><?= $this->site ?></text>
	</g>
</svg>
<?php
		return self::XML_HEADER . ob_get_clean();
	}

	/* =========== hooks =============== */

	/*
	 * Insertion d'une entrée pour télécharger un livre électronique (ebook)
	 * dans le menu du site.
	 * */
	public function plxShowStaticListEnd() {
		echo self::BEGIN_CODE;
?>
$kzListing = $this->plxMotor->plxPlugins->aPlugins['<?= __CLASS__ ?>']->listing();
foreach($kzListing as $l=>$caption) {
	$kzStat = strtr($format, array(
		'#static_id'		=> 'epub-' . $l,
        '#static_class'		=> 'static epub',
        '#static_url'		=> $this->plxMotor->urlRewrite('?epub/' . $l),
        '#static_name'		=> plxUtils::strCheck($caption),
        '#static_status'	=> 'noactive',
	));
	if(count($kzListing) === 1) {
		$menus[][] = $kzStat;
	} else {
		$kzPlugin = $this->plxMotor->plxPlugins->aPlugins['<?= __CLASS__ ?>'];
		$menus[$kzPlugin->getLang('EBOOKS')][] = $kzStat;
	}
}

# On continue dans plxShow::plxShowStaticListEnd()
return false;
<?php
		echo self::END_CODE;
	}

	/*
	 * Traite la demande pour télécharger un livre électronique.
	 * */
	public function plxMotorPreChauffageBegin() {
		echo self::BEGIN_CODE;
?>
if (empty($this->get) or !preg_match('#^epub/(stat|group|template|home|cat)/([\w-]+)$#u', urldecode($this->get), $kzMatches)) {
	return false;
}

if($this->plxPlugins->aPlugins['<?= __CLASS__ ?>']->sendEpub($kzMatches[1], $kzMatches[2])) {
	exit;
} else {
	$this->error404(L_DOCUMENT_NOT_FOUND);
}

<?php
		echo self::END_CODE;
	}

}
