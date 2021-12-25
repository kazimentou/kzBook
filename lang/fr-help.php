<h2>Insertion automatique d'une entrée dans le menu</h2>
<p>La méthode plxBook::listing() génére automatiquement une entrée dans le menu avec la liste de tous les livres électroniques qui peuvent être générés automatiquement à la demande.</p>
<h2>Créer une page statique pour lister des livres électroniques</h2>
<p>Créer une page statique et éditer son contenu avec PluXml avec, par exemple, le code ci-dessous :</p>
<pre><code>
&lt;h3&gt;Ma sélection&lt;/h3&gt;
&lt;?php
eval($this-&gt;callHook('kzBook', array(
	array('stat', 'all'), # Toutes les pages statiques
	array('group', 'Démo'), # Pages statiques classées dans le groupe "Démo"
	array('template', 'static'), # Pages statiques utulisant le template static.php
	array('cat', 0), # articles non classés
	array('cat', 1), # articles classés dans la catégorie '001'
	array('cat', 2, 'Ma catégorie préférée'), # articles classés dans la catégorie '002'
	array('cat', 'all'), # tous les articles
)));
?&gt;
</code></pre>
