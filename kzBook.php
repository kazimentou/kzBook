<?php

if (!defined('PLX_ROOT')) {
	exit;
}

# http://idpf.org/epub/20/spec/OPF_2.0_latest.htm

/**
 * class kzBook Génére un livre électronique à partir d'une sélection de pages statiques ou d'articles
 *
 * @author Jean-Pierre Pourrez aka "bazooka07"
 **/
class kzBook extends plxPlugin {
	const HOOKS = array(
		'plxShowStaticListEnd',
		'plxMotorConstruct',
		'plxMotorPreChauffageBegin',
		'kzBook',
		'plxAdminEditArticleEnd',
		'plxAdminEditStatique',
	);

	# Ne pas inclure l'entête XML dans un script PHP (Erreur de syntaxe sinon).
	const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;

	const BEGIN_CODE = '<?php' . PHP_EOL . '# ' . __CLASS__ . PHP_EOL;
	const END_CODE = PHP_EOL . '?>';

	# filtre pour les urls qui déclenchent la génération d'un livre élecronique
	const PATTERN1 = 'stat|group|template|cat';
	const PATTERN_MENU = '#^epub/(' . self::PATTERN1 . ')/([\w-]+)$#u';

	const CONTAINER = <<< EOT
<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">
	<rootfiles>
		<rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml" />
	</rootfiles>
</container>
EOT;
	# Cadres pour la page de couverture par défaut
	# const IMG_COVER_TEMPLATE = 'cover-basic.php';
	const IMG_COVER_TEMPLATE = 'cover-decorative.php';
	const IMG_COVER_NAME = 'cover.svg';

	const IMG_PATTERN = '#\.(?:jpe?g|gif|png|bmp|svg)$#i';
	const STATIC_TEMPLATE = 'static.php';

	const EXTS = array(
		'jpeg'	=> 'jpg',
		'svg'	=> 'svg+xml',
		'svgz'	=> 'svg+xml',
		'ico'	=> 'vnd.microsoft.icon',
	);

	const MENU_NOENTRY = -20;
	const MAX_LIFE = 7 * 24 * 3600; # secondes. Durée de vie des ebooks sur le serveur

	private $_folder = false; # Dossier pour stocker les ebooks construits et les images de couverture
	private $_style = false;
	private $_bookName = 'foo';
	private $_filename = false;
	private $_pattern = false;
	private $_imgCover = false;
	public $cats = false;
	public $staticGroups = false;
	public $staticTemplates = false;

	function __construct($default_lang) {
		parent::__construct($default_lang);
		if (defined('PLX_ADMIN')) {
			parent::setConfigProfil(PROFIL_ADMIN);
		}
		foreach(self::HOOKS as $hk) {
			$this->addHook($hk, $hk);
		}
	}

	private function _getMimetype($filename) {
		$ext = strtolower(pathinfo($filename,  PATHINFO_EXTENSION));
		if (isset(self::EXTS[$ext])) {
			$ext = self::EXTS[$ext];
		}
		return 'image/' . $ext;
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

	/*
	 * Retourne le contenu de la page de couverture.
	 * */
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
		$lastDate = time() - self::MAX_LIFE; # On supprime les ebooks trop anciens
		foreach(glob(PLX_ROOT . $plxMotor->aConf['medias'] . __CLASS__ . '/pluxml-*.epub') as $f) {
			if (filemtime($f) < $lastDate) {
				unlink($f);
			}
		}
		if (file_exists($this->_filename)) {
			# L'ebook existe sur le serveur. Rien à faire
			return;
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
				$guides = Array();

				# Image pour la page de couverture du livre
				if (empty($this->_imgCover)) {
					# On crée une image par défaut pour la couverture
					ob_start();
					include $this->_style . self::IMG_COVER_TEMPLATE;
					$content = self::XML_HEADER . ob_get_clean();
					$href = 'text/' . self::IMG_COVER_NAME;
					$zip->addFromString('OEBPS/' . $href, $content);
				} else {
					$href = 'text/cover.' . pathinfo($this->_imgCover,  PATHINFO_EXTENSION);
					$zip->addFile($this->_imgCover, 'OEBPS/' . $href);
				}
				$this->_imgCoverManifest = $href;

				# On crée la page de couverture
				# l'image de couverture est au même niveau que la page htlml de couverture.
				$content = $this->_buildCover(basename($href));
				if(!empty($content)) {
					$hrefCover = 'text/cover.html';
					$idx = 'cover';
					$zip->addFromString('OEBPS/' . $hrefCover, $content);
					$manifest[$idx] = $hrefCover;
					$spine[] = $idx;
					$guides[$idx] = $hrefCover;
					$toc[$hrefCover] = $this->getLang('COVER');
				}

				if (is_array($stats)) {

					# ============= On traite des pages statiques ==============

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
				} elseif (in_array($stats, Array('arts', 'all'))) {

					# ============= On traite des articles ==============

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
							if ($stats != 'all') {
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

					if($stats == 'all') {
						# Plusieurs catégories à trier
						uksort($toc, function($key1, $key2) {
							# Articles de la page d'accueil en premiers
							if ($key1 == 'home') {
								return -1;
							}
							if ($key2 == 'home') {
								return 1;
							}
							# Articles non classés à la fin
							if ($key1 == '000') {
								return 1;
							}
							if ($key2 == '000') {
								return -1;
							}
							return ($key1 - $key2);
						});
					}

					if (!empty($tagsList)) {
						# On génére une page index
						ksort($tagsList);
						$plxMotor->kzTags = $tagsList;
						$content = $this->_buildArtsIndex();
						if (!empty($content)) {
							$idx = 'tags';
							$hrefIndex = $idx . '.html';
							$zip->addFromString('OEBPS/' . $hrefIndex, $content);
							$manifest[$idx] = $hrefIndex;
							$spine[] = $idx;
							$toc[$hrefIndex] = $this->getLang('INDEX');
							$guides['index'] = $hrefIndex;
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
					$guides['toc'] = $hrefToc;
				} # !$empty($toc)

				# =========== Génération de content.opf ===============

				ob_start();
?>
<package xmlns:opf="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns="http://www.idpf.org/2007/opf" xmlns:ox="http://www.editeur.org/onix/2.1/reference/" version="2.0" unique-identifier="ean">
	<metadata>
		<dc:title>Les pages de <?= $plxMotor->aConf['title']; ?></dc:title>
		<dc:subject><?= $plxMotor->aConf['description'] ?></dc:subject>
		<dc:source><?= $plxMotor->racine ?></dc:source>
		<dc:creator><?= $this->author ?></dc:creator>
		<dc:rights>Copyright © <?= date('Y') ?></dc:rights>
		<dc:language><?= $plxMotor->aConf['default_lang']; ?></dc:language>
		<dc:date opf:event="creation"><?= date('Y-m-d') ?></dc:date>
		<dc:format>epub</dc:format>
		<dc:identifier opf:scheme="UUID"><?= time() ?></dc:identifier>
<?php
/*
		<dc:date opf:event="modification">[Votre valeur ici]</dc:date>
		<dc:rights>[Votre valeur ici]</dc:rights>
 * */
?>
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

				# Image pour la page de couverture
				if (!empty($this->_imgCoverManifest)) {
					$str = $this->_imgCoverManifest;
?>
		<item id="cover-img" href="<?= $str ?>" media-type="<?= $this->_getMimetype($str) ?>" />
<?php
				}

				# Images présentes dans les pages HTML
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
		<item id="media-<?= $id ?>" href="<?= $href ?>" media-type="<?= $this->_getMimetype($href) ?>"" />
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
<?php
				if (!empty($guides)) {
?>
	<guide>
<?php
					foreach($guides as $k=>$href) {
?>
		<reference type="<?= $k ?>" title="<?= $this->getLang(strtoupper($k)) ?>" href="<?= $href ?>" />
<?php
					}
?>
	</guide>
<?php
				}
?>
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

	/**
	 * Génère et télécharge le livre électronique au format epub.
	 *
	 * @param $scope peut prendre la valeur stat, group, template ou cat
	 * @param $value dépend de la valeur de $scope
	 *
	 * si $scope == 'stat', alors $value non significatif. On sélectionne toutes les pages statiques
	 * si $scope == 'group', alors nom du groupe de pages statiques
	 * si $scope == 'template', alors nom d'un template sans l'extension '.php'
	 * si $scope == 'cat', alors indice d'une catégorie d'une catégorie d'articles ou 'all' pour tous les articles
	 **/
	public function sendEpub($scope, $value) {
		# la class plxShow existe !
		$plxShow = plxShow::getInstance();
		$plxMotor = $plxShow->plxMotor;

		# Nouvelle propriété pour $plxMotor
		$plxMotor->mode_extra = $scope;

		$this->_folder = PLX_ROOT . $plxMotor->aConf['medias'] . __CLASS__;
		if (!is_dir($this->_folder) and !mkdir($this->_folder)) {
			return false;
		}

		$folder = $this->_folder . '/covers';
		if (is_dir($folder) or mkdir($folder)) {
			foreach(Array('/stat', '/group', '/template', '/cat') as $f) {
				$g = $folder . $f;
				if(!is_dir($g) and !mkdir($g)) {
					break;
				}
			}
		}

		if ($scope == 'cat') {
			# Sélection d'articles
			$str = 'cat-';
			switch($value) {
				case 'home':
					# articles affichés en page d'accueil
					$str .= 'home-arts';
					break;
				case '000':
					# Articles non classés
					$str .= 'unclassified-arts';
					break;
				case 'all':
					# Tous les articles
					$str .= 'all-arts';
					break;
				default:
					if (preg_match('#^\d{1,3}$#', $value)) {
						# Articles pour une catégorie donnée
						$k = str_pad($value, 3, '0', STR_PAD_LEFT);
						if (
							isset($plxMotor->aCats[$k]['name']) and
							!empty($plxMotor->aCats[$k]['active']) and
							!empty($plxMotor->aCats[$k]['articles'])
						) {
							$str .=  $plxMotor->aCats[$k]['name'];
						} else {
							return false;
						}
					} else {
						return false;
					}
			}
		} else {
			# pages statiques
			$str = $scope . '-' . $value;
		}
		# $this->_bookName = 'pluxml-' . preg_replace('#[^\w-]+#', '_', strtolower($str)) . '.epub';
		$this->_bookName = 'pluxml-' . plxUtils::urlify($str, false, '_') . '.epub';
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
					case 'group':
						$this->title = $this->getLang('GROUP');
						$this->subTitle = ucfirst($value);
						break;
					case 'template':
						$this->title = $this->getLang('TEMPLATE');
						$this->subTitle = ucfirst($value);
						break;
					default: $this->title = $this->getLang('PAGES');
				}
				$this->_getImgCover($scope, $value);
				self::_build($stats);
				break;
			case 'cat':
				# On mime plxShow::prechauffage().
				$plxMotor->template = 'article.php';
				switch($value) {
					case '000' :
					case 'home':
						if ($value == 'home') {
							$this->title = $this->getLang('CATEGORY_HOME_PAGE');
						} else {
							$this->title = $this->getLang('ARTICLES');
							$this->subTitle = $this->getLang('UNCLASSIFIED');
						}
						$plxMotor->cible = $value;
						$catsPattern = ($value == 'home') ? '(?:\d{3},)*home(?:,\d{3})*' : '000';
						$plxMotor->tri = $plxMotor->aConf['tri'];
						break;
					case 'all':
						$this->title = $this->getLang('ARTICLES');
						$this->subTitle = $this->getLang('ALL_SITE');
						$plxMotor->cible = '';
						$catsPattern = '(?:\d{3},|home,)*\d{3}(?:,home|,\d{3})*';
						$plxMotor->tri = 'asc';
						$plxMotor->mode_extra = 'all';
						break;
					default:
						$idCat = str_pad($value, 3, '0', STR_PAD_LEFT);
						if (empty($plxMotor->aCats[$idCat]['active'])) {
							return false;
						}
						$this->title = $this->getLang('CATEGORY');
						$this->subTitle = $plxMotor->aCats[$idCat]['name'];
						$plxMotor->cible = $idCat;
						$catsPattern = '(?:\D{3},|HOME,)*' . $idCat . '(?:,home|,\d{3})*';
						$cat = $plxMotor->aCats[$idCat];
						$plxMotor->template = $cat['template'];
						# Recuperation du tri des articles
						$plxMotor->tri = $cat['tri'];
				}


				$plxMotor->mode = 'categorie';
				$plxMotor->bypage = false; # unlimited !
				# Motif de recherche des articles
				$plxMotor->motif = '#^\d{4}\.' . $catsPattern . '\.\d{3}\.\d{12}\.[\w-]+\.xml$#';
				# Recherche les articles pour la sélection
				$plxMotor->demarrage();

				$this->_getImgCover($scope, $value);
				$this->_build($value != 'all' ? 'arts' : 'all');
				break; # end of "case 'cat':"
			default:
				# Contexte inconnu. Bye !
				return false;
		}

		if (!empty($this->_filename) and file_exists($this->_filename)) {
			# l'ebook existe. On peut le télécharger.
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
		$result = array();

		# Catégories
		$value = trim($this->getParam('cats'));
		if (!empty($value)) {
			$plxMotor = plxMotor::getInstance();
			$pattern = $this->getLang('CATEGORY_PATTERN');
			foreach(explode(',', $value) as $catId) {
				switch($catId) {
					case 'home' : $name = $this->getLang('HOMEPAGE_ARTS'); break; # Articles en page d'accueil
					case '000' : $name = $this->getLang('UNCLASSIFIED_ARTS'); break; # Articles non classés
					case 'all' : $name = $this->getLang('ALL_ARTICLES'); break; # Tous les articles
					default:
						$name = isset($plxMotor->aCats[$catId]) ? sprintf($pattern, $plxMotor->aCats[$catId]['name']) : false;
				}
				if (empty($name)) { continue; }
				$result['cat/' . $catId] = $name;
			}
		}

		# groupes des pages statiques
		$value = trim($this->getParam('static_groups'));
		if (!empty($value)) {
			$pattern = $this->getLang('GROUP_PATTERN');
			foreach(explode(',', $value) as $gr) {
				if ($gr == '__all__') {
					$result['stat/all'] = $this->getLang('ALL_STATS');
					continue;
				}

				$result['group/' . urlencode($gr)] = sprintf($pattern, $gr);
			}
		}

		# Gabarits des pages statiques
		$value = trim($this->getParam('static_templates'));
		if (!empty($value)) {
			$pattern = $this->getLang('TEMPLATE_PATTERN');
			foreach(explode(',', $value) as $tp) {
				$result['template/' . $tp] = sprintf($pattern, $tp);
			}
		}

		return $result;
	}

	/* =========== hooks =============== */

	/*
	 * Insertion d'une entrée pour télécharger un livre électronique (ebook)
	 * dans le menu du site.
	 * */
	public function plxShowStaticListEnd() {
		$pos = $this->getParam('menu_pos');
		$pos = preg_match('#-?\d{1,2}$#', $pos) ? intval($pos) : self::MENU_NOENTRY - 1;
		if ($pos == self::MENU_NOENTRY) {
			# Pas d'insertion d'entrée dans le menu du site
			return;
		}

		$title = $this->getParam('menu_title');
		if (empty($title)) {
			$this->getLang('EBOOKS');
		}

		echo self::BEGIN_CODE;
?>
$kzPlugin = $this->plxMotor->plxPlugins->aPlugins['<?= __CLASS__ ?>'];
$kzListing = $kzPlugin->listing();
if (empty($kzListing)) {
	# Nothing to do
	return false;
}

$kzPos = <?= $pos ?>;
if (count($kzListing) > 1) {
	# Sous-menu à créer
	$kzGroup = '<?= $title ?>';
	if ($kzPos == -1 or $kzPos >= count($menus)) {
		# En fin de menu
		$menus[$kzGroup] = array();
	} elseif ($kzPos == 0 or ($kzPos < 0 and $kzPos <= -count($menus)) ) {
		# On se place en début de menu
		$menus = array_merge(array($kzGroup => array()), $menus);
	} else {
		# position intermédiaire
		# array_splice() ne préserve pas les clés !!!
		$menus = array_merge(
			array_slice($menus, 0, ($kzPos > 0) ? $kzPos : count($menus) + $kzPos, true),
			array($kzGroup => array()),
			array_slice($menus, $kzPos, count($menus), true)
		);
	}
}

foreach($kzListing as $l=>$caption) {
	$kzStat = strtr($format, array(
		'#static_id'		=> 'epub-' . $l,
        '#static_class'		=> 'static epub',
        '#static_url'		=> $this->plxMotor->urlRewrite('?epub/' . $l),
        '#static_name'		=> plxUtils::strCheck($caption),
        '#static_status'	=> 'noactive',
	));
	if(isset($menus[$kzGroup])) {
		$menus[$kzGroup][] = $kzStat;
	} else {
		$menus = array_splice($menus, <?= $pos ?>, 0, $kzStat);
		break;
	}
}

# On continue dans plxShow::plxShowStaticListEnd()
return false;
<?php
		echo self::END_CODE;
	}


	/**
	 * Génére des listes de catégories d'articles, de groupes et de templates de pages statiques disponibles.
	 **/
	public function plxMotorConstruct() {
		if (defined('PLX_ADMIN') and !preg_match('#='. __CLASS__ . '\b#', $_SERVER['QUERY_STRING'])) {
			# Ne rien faire
			return;
		}

		$translations =array(
			addslashes($this->getLang('HOMEPAGE_ARTS')), # Articles en page d'accueil
			addslashes($this->getLang('UNCLASSIFIED_ARTS')), # Articles non classés
			addslashes($this->getLang('ALL_ARTICLES')), # Tous les articles
		);
		echo self::BEGIN_CODE;
?>
$kzPlugin = $this->plxPlugins->aPlugins['<?= __CLASS__ ?>'];
$kzPlugin->aCats = array_merge(
	array(
		'all'	=> array(
			'name' => '<?= $translations[2] ?>',
			'articles' => count($this->activeArts),

		),
	),
	array_filter($this->aCats, function($v) {
		return (!empty($v['active']) and $v['articles'] > 0);
	}),
	array(
		'home'	=> array('name' => '<?= $translations[0] ?>'),
		'000'	=> array('name' => '<?= $translations[1] ?>'),
	)
);

$kzPlugin->staticGroups = array_merge(
	array('__all__'),
	array_unique(array_map(
		function($value) {
			return $value['group'];
		},
		array_values(
			array_filter(
				$this->aStats,
				function($value) {
					return (!empty($value['active']) and !empty($value['group']));
				}
			)
		)
	))
);

$kzPlugin->staticTemplates = array_unique(array_values(
	array_map(
		function($value) {
			return basename($value['template'], '.php');
		},
		array_filter(
			$this->aStats,
			function ($value) {
				return !empty($value['active']);
			}
		)
	)
));
<?php
		echo self::END_CODE;
	}

	/*
	 * Traite la demande pour télécharger un livre électronique.
	 * */
	public function plxMotorPreChauffageBegin() {
		echo self::BEGIN_CODE;
?>
if (preg_match('<?= self::PATTERN_MENU ?>', urldecode($this->get), $kzMatches)) {
	if($this->plxPlugins->aPlugins['<?= __CLASS__ ?>']->sendEpub($kzMatches[1], $kzMatches[2])) {
		exit;
	} else {
		$this->error404(L_DOCUMENT_NOT_FOUND);
	}
}
<?php
		echo self::END_CODE;
	}

	public function kzBook($params) {
		if (!is_array($params)) {
			return;
		}
?>
	<ul class="kzbook">
<?php
		$plxMotor = plxMotor::getInstance();
		foreach($params as $p) {
			if(!is_array($p) or count($p) < 2) {
				continue;
			}

			$scope = $p[0];
			$value = $p[1];

			if (!in_array($scope, explode('|', self::PATTERN1))) { # stat|group|template|cat
				continue;
			}

			$flag = (count($p) < 3); # Pas de titre personnalisé
			switch($scope) {
				case 'stat':
					$caption = $flag ? $this->getLang('ALL_STATS') : $p[2];
					$value = 'all';
					break;
				case 'group':
					$caption = $flag ? sprintf($this->getLang('GROUP_PATTERN'), $value) : $p[2];
					$value = urlencode($value);
					break;
				case 'template':
					$caption = $flag ? sprintf($this->getLang('TEMPLATE_PATTERN'), $value) : $p[2];
					$value =  basename($value, '.php');
					break;
				default: # 'cat'
					$value = str_pad($value, 3, '0', STR_PAD_LEFT);
					switch($value) {
						case '000': $caption = $flag ? $this->getLang('UNCLASSIFIED_ARTS') : $p[2]; break;
						case 'all':  $caption = $flag ? $this->getLang('ALL_ARTICLES') : $p[2]; break;
						case 'home': $caption = $flag ? $this->getLang('HOMEPAGE_ARTS') : $p[2]; break;
						default:
							if (isset($plxMotor->aCats[$value])) {
								$caption = $flag ? sprintf($this->getLang('CATEGORY_PATTERN'), $plxMotor->aCats[$value]['name']) : $p[2];
							}
					}
			}

			if(empty($caption)) {
				continue;
			}
?>
		<li><a href="<?= $plxMotor->urlRewrite('index.php?epub/' . $scope . '/' . $value) ?>"><?= $caption ?></a></li>
<?php
		}
?>
	</ul>
<?php
	}

	/**
	 * Suppression des ebooks relatifs aux catégories en cas d'édition d'un article.
	 **/
	public function plxAdminEditArticleEnd() {
		echo self::BEGIN_CODE;
?>
foreach(glob(glob(PLX_ROOT . $this->aConf['medias'] . '<?= __CLASS__ ?>/pluxml-cat-*.epub') as $f) {
	unlink($f);
}
<?php
		echo self::END_CODE;
	}

	/**
	 * Suppression des ebooks relatifs aux catégories en cas d'édition d'une page statique.
	 **/
	public function plxAdminEditStatique() {
		echo self::BEGIN_CODE;
?>
foreach(glob(glob(PLX_ROOT . $this->aConf['medias'] . '<?= __CLASS__ ?>/pluxml-*.epub') as $f) {
	if (preg_match('#^pluxml-(?:stat|group|template)-#', basename($f))) {
		unlink($f);
	}
}
<?php
		echo self::END_CODE;
	}

}
