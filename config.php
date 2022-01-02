<?php
if (!defined('PLX_ROOT')) {
	exit;
}

plxToken::validateFormToken();

$themes = array_map(
	function($value) {
		return preg_replace('#.*/([\w-]*)$#', '$1', $value);
	},
	glob(__DIR__ . '/themes/*',  GLOB_ONLYDIR)
);

$statics = array(
	'static_groups'	=> $plxPlugin->staticGroups,
	'static_templates'	=> $plxPlugin->staticTemplates,
);

$filters = array(
	'menu_pos'	=> array(
		'filter'		=> FILTER_VALIDATE_INT,
		'options'		=> array('min_range' => kzBook::MENU_NOENTRY, 'max_range' => 20),
	),
	'menu_title'		=> array(
		'filter'	=> FILTER_SANITIZE_STRING,
	),
	'theme'				=> array(
		'filter'	=> FILTER_VALIDATE_REGEXP,
		'options'	=> array('regexp' => '#^(?:' . implode('|', $themes) . ')?$#'),
	),
	'cats'				=> array(
		'filter'	=> FILTER_VALIDATE_REGEXP,
		'options'	=> array('regexp' => '#^(?:\d{3}|home|all)?$#'),
		'flags'		=> FILTER_REQUIRE_ARRAY,
	),
	'static_groups'		=> array(
		'filter'	=> FILTER_VALIDATE_REGEXP,
		'options'	=> array('regexp' => '#^(?:' . implode('|', $statics['static_groups']) . ')?$#'),
		'flags'		=> FILTER_REQUIRE_ARRAY,
	),
	'static_templates'	=> array(
		'filter'	=> FILTER_VALIDATE_REGEXP,
		'options'	=> array('regexp' => '#^(?:' . implode('|', $statics['static_templates']) . ')?$#'),
		'flags'		=> FILTER_REQUIRE_ARRAY,
	),
);

$params = filter_input_array(INPUT_POST, $filters);

if (!empty($params)) {
	foreach($params as $k=>$v) {
		if ($k == 'menu_pos' or !empty($v)) {
			$plxPlugin->setParam($k, is_array($v) ? implode(',', $v) : $v, ($filters[$k]['filter'] == FILTER_VALIDATE_INT) ? 'numeric' : 'string');
		} else {
			# On n'enregistre pas les paramètres avec une valeur nulle.
			$plxPlugin->delParam($k);
		}
	}
	$plxPlugin->saveParams();
	header('Location: parametres_plugin.php?p=' . $plugin);
	exit;
}

$menus = $filters['menu_pos']['options'];
$numPos = $plxPlugin->getParam('menu_pos');
if (strlen($numPos) == 0) {
	$numPos = '-1';
}
?>

<form class="<?= strtolower($plugin) ?>" method="post">
	<label>
		<span><?php $plxPlugin->lang('MENU_POS') ?></span>
		<input type="number" name="menu_pos" value="<?= $numPos ?>" min="<?= $menus['min_range'] ?>" max="<?= $menus['max_range'] ?>" />
		<a class="hint"><span><?= nl2br($plxPlugin->getLang('HELP_MENU_POS')) ?></span></a>
	</label>
	<label>
		<span><?php $plxPlugin->lang('MENU_TITLE') ?></span>
		<input type="text" name="menu_title" value="<?= $plxPlugin->getParam('menu_title') ?>" />
	</label>
	<label>
		<span><?php $plxPlugin->lang('THEME') ?></span>
		<select name="theme">
<?php
$value = $plxPlugin->getParam('theme');
if (empty($value)) {
	$value = 'default';
}
foreach($themes as $v) {
?>
			<option <?= ($value == $v) ? 'selected' : '' ?>><?= $v ?></option>
<?php
}
?>
		</select>
	</label>
	<h3><?php $plxPlugin->lang('CRITERIA') ?> :</h3>
	<label>
		<span><?php $plxPlugin->lang('CATEGORIES') ?></span>
		<select name="cats[]" multiple>
<?php
$values = explode(',', $plxPlugin->getParam('cats'));
foreach($plxPlugin->aCats as $k=>$v) {
	$selected = in_array($k, $values) ? ' selected' : '';
	$artCount = isset($v['articles']) ? ' (' . $v['articles'] . ')' : '';
?>
			<option value="<?= $k ?>"<?= $selected ?>><?= $v['name'] . $artCount ?></option>
<?php
}
?>
		</select>
	</label>
<?php
foreach($statics as $fieldname=>$datas) {
?>
	<label>
		<span><?php $plxPlugin->lang(strtoupper($fieldname)) ?></span>
		<select name="<?= $fieldname ?>[]" multiple>
<?php
	$values = explode(',', $plxPlugin->getParam($fieldname));
	foreach($datas as $v) {
		$selected = in_array($v, $values) ? ' selected' : '';
		$caption = ($fieldname != 'static_groups' or $v != '__all__') ? $v : $plxPlugin->getLang('ALL_STATS');
?>
			<option value="<?= $v ?>"<?= $selected ?>><?= $caption ?></option>
<?php
	}
?>
		</select>
	</label>
<?php
}
?>
	<div class="infos">
		<p><em><?= nl2br($plxPlugin->getLang('HELP_SELECT')) ?></em></p>
	</div>
	<div class="text-center">
		<input type="submit" />
		<?= plxToken::getTokenPostMethod() . PHP_EOL ?>
	</div>
</form>
<style>
<!--
form.kzbook select {
		height: auto;
		background-image: none;
		max-width: none;
		padding: initial;
		overflow: auto;
}

form.kzbook input[type="number"] {
	width: 6rem;
}

form.kzbook .infos {
	font-size: small;
	text-align: center;
}

@media screen and (max-width: 575px) {
	form.kzbook select,
	form.kzbook input[type="text"] {
		width: 100%;
	}
}

@media screen and (min-width: 576px) {
	form.kzbook {
		width: fit-content;
		margin: 0.5rem auto;
		padding: 0.3rem 0.5rem;
		border: 1px solid #666;
		border-radius: 0.5rem;
	}
	form.kzbook label > span {
		display: inline-block;
		min-width: 22rem;
		text-align: end;
		vertical-align: top;
	}
	form.kzbook select,
	form.kzbook input[type="text"] {
		min-width: 25rem;
	}
}
-->
</style>
