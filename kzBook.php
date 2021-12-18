<?php

if (!defined('PLX_ROOT')) {
	exit;
}

class kzBook extends plxPlugin {
	const HOOKS = array(
		'plxShowStaticListEnd',
		'plxMotorPreChauffageBegin',
	);

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

	private $_folder = false;
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

	private function _buildStatic($id, $v) {
		$inc = $this->_style . $v['template'];
		if (!file_exists($inc)) {
			return false;
		}

		# Pré-requis pour le script $inc.
		$plxShow = plxShow::getInstance();
		$plxMotor = $plxShow->plxMotor;

		# Génération du contenu avec l'include.
		ob_start();
		include $inc;
		$content = ob_get_clean();

		# On ajuste les urls pour les liens et les images.
		return $this->_updateLinks($content);
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
		$content = ob_get_clean();

		# On ajuste les urls pour les liens et les images.
		return $this->_updateLinks($content);
	}

	function _build($stats) {
		$plxMotor = plxMotor::getInstance();
		if (file_exists($this->_filename)) {
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
				if (is_array($stats)) {
					# Par défaut, on traite les pages statiques recensées dans $stats
					$plxMotor->mode = 'static';
					foreach($stats as $k=>$v) {
						# On mime plxShow::prechauffage()
						$plxMotor->get = 'static' . $k . '/' . $v['url'];
						$plxMotor->cible = $k;

						$template = preg_replace('#-full-width\.php$#', '.php', $v['template']);
						$plxMotor->template = file_exists($this->_style . $template) ? $template : 'static.php';

						# Aucune action dans plxMotor::demarrage() pour le mode 'static'. Peut-être un plugin ?
						# $plxMotor->demarrage();

						$content = $this->_buildStatic($k, $v);
						if (!empty($content)) {
							# On évite d'archiver un contenu vide ( absence de template ).
							$href = 'text/stat-' . $k . '.html';
							$zip->addFromString('OEBPS/' . $href, $content);
							$manifest[$k] = $href;
							$spine[] = $k;
						}
					}
				} elseif ($stats === 'arts') {
					# On traite une sélection d'articles

					# Pas de nouveau commentaire
					$plxMotor->aConf['allow_com'] = 0;

					$plxMotor->mode = 'article';
					while ($plxMotor->plxRecord_arts->loop()) {
						$artId = $plxMotor->plxRecord_arts->f('numero');
						$plxMotor->cible = $artId;
						$content = $this->_buildArt();

						if (!empty($content)) {
							# On évite d'archiver un contenu vide ( absence de template ).
							$k = 'art-' . $artId;
							$href = 'text/' . $k . '.html';
							$zip->addFromString('OEBPS/' . $href, $content);
							$manifest[$k] = $href;
							$spine[] = $k;
						}
					}
				}

				# Génération du contenu de content.opf
				ob_start();
				/*
	Dans <metadata /> :
		<dc:identifier id="ean">9782335005110</dc:identifier>
		<meta name="cover" content="img_Cover"/>
	Dans <manifest /> :
		<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
				 * */

?>
<package xmlns:opf="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns="http://www.idpf.org/2007/opf" xmlns:ox="http://www.editeur.org/onix/2.1/reference/" version="2.0" unique-identifier="ean">
	<metadata>
		<dc:title>Les pages de <?= $plxMotor->aConf['title']; ?></dc:title>
		<dc:publisher><?= $plxMotor->racine ?></dc:publisher>
		<dc:creator><?= $plxMotor->aUsers['001']['name'] ?></dc:creator>
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
	<guide>
		<reference type="cover" title="Cover Image" href="Cover.html"/>
		<reference type="text" title="d3e88" href="d3e88.html"/>
	</guide>
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
	 * Génère et télécharge le livre électronique au format epub.
	 * */
	public function sendEpub($scope, $value) {
		# la Class plxShow existe !
		$plxShow = plxShow::getInstance();
		$plxMotor = $plxShow->plxMotor;

		$this->_folder = PLX_ROOT . $plxMotor->aConf['medias'] . __CLASS__;
		if (!is_dir($this->_folder) and !mkdir($this->_folder)) {
			return false;
		}

		$str = ($scope != 'cat') ? $value : $plxMotor->aCats[str_pad($value, 3, '0', STR_PAD_LEFT)]['name'];
		$this->_bookName = 'pluxml-' . $scope . '-' . str_replace(' ', '_', strtolower($str)) . '.epub';
		$this->_filename = $this->_folder . '/' . $this->_bookName;
		$this->_pattern = '#\b(href|src)="(?:' . $plxMotor->racine . ')?'. $plxMotor->aConf['medias'] . '([^"]*)#';


		$style = $this->getParam('style');
		if (empty($style)) {
			$style = 'default';
		}
		$this->_style = PLX_PLUGINS . __CLASS__ . '/themes/' . $style . '/';
		$plxMotor->mode = (in_array($scope, array('stat', 'group', 'template'))) ? 'static' : 'article';
		switch ($scope) {
			case 'stat':
			case 'group':
			case 'template':
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
				self::_build($stats);
				break;
			case 'art':
				return false;
				break;
			case 'cat':
				# A implémenter
				$idCat = str_pad($value, 3, '0', STR_PAD_LEFT);
				if (empty($plxMotor->aCats[$idCat]['active'])) {
					return false;
				}

				# On mime plxShow::prechauffage().
				$plxMotor->cible = $idCat;
				$cat = $plxMotor->aCats[$idCat];
				$plxMotor->mode = 'categorie';
				# Motif de recherche des articles
				$plxMotor->motif = '#^\d{4}.((?:\d|home|,)*(?:' . $idCat . ')(?:\d|home|,)*)\.\d{3}\.\d{12}\.[\w-]+\.xml$#';
				$plxMotor->template = $cat['template'];
				# Recuperation du tri des articles
				$plxMotor->tri = $cat['tri'];
				$plxMotor->bypage = false; # unlimited !

				$plxMotor->demarrage();

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
			'stat/all'	=> 'Toutes les pages statiques',
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
		foreach($groups as $gr) {
			$result['group/' . urlencode($gr)] = 'Groupe ' . $gr;
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
		foreach($templates as $tp) {
			$result['template/' . $tp] = 'Gabarit ' . $tp;
		}

		# Catégories
		$cats = array_map(
			function($value) {
				return $value['name'];
			},
			array_filter(
				$plxMotor->aCats,
				function($value) {
					return  !empty($value['active']);
				}
			)
		);
		asort($cats);
		foreach($cats as $id=>$name) {
			$result['cat/' . $id] = 'Categorie ' . $name;
		}

		return $result;
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
		$menus['epub'][] = $kzStat;
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
if (empty($this->get) or !preg_match('#^epub/(stat|group|template|art|cat)/([\w-]+)$#u', urldecode($this->get), $kzMatches)) {
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
