<?php
/* Book Creator plugin for CollectiveAccess
 *
 * Plugin by idéesculture – Gautier MICHELIN
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License v3. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */
 
	class bookCreatorPlugin extends BaseApplicationPlugin {
		# -------------------------------------------------------
		protected $description = 'Create printed books directly from CollectiveAccess';
		# -------------------------------------------------------
		private $opo_config;
		private $ops_plugin_path;
		# -------------------------------------------------------
		public function __construct($ps_plugin_path) {
			$this->ops_plugin_path = $ps_plugin_path;

			// Traductions du plugin. CollectiveAccess ne prévoit aucun emplacement
			// de catalogue dans un plugin (voir l'en-tête de lib/IdC.php) : le
			// helper IdC est embarqué par chaque plugin IdéesCulture et enregistré
			// par le premier chargé. Il fait sa propre interpolation des %n, ce qui
			// évite d'avoir à corriger _t() dans le coeur.
			if (!class_exists('IdC', false)) {
				require_once($ps_plugin_path.'/lib/IdC.php');
			}
			// Nom de fichier préfixé : Configuration::load() fusionne
			// inconditionnellement app/conf/<même nom>, ce qui viderait un
			// catalogue nommé translations.conf (voir lib/IdC.php).
			if (!IdC::registered('bookCreator')) {
				IdC::registerFile('bookCreator', $ps_plugin_path.'/conf/bookCreator_translations.conf');
			}

			parent::__construct();
			$this->opo_config = Configuration::load($ps_plugin_path.'/conf/bookCreator.conf');
		}
		# -------------------------------------------------------
		/**
		 * Traduite à l'appel et non dans le constructeur : la description était
		 * figée à la locale en vigueur au premier chargement des plugins du
		 * processus. Sans effet sur une requête web (la locale est établie avant
		 * initPlugins), mais faux en ligne de commande ou dans tout processus qui
		 * change de locale en cours de route.
		 */
		public function getDescription() {
			return IdC::_t('Create printed books directly from CollectiveAccess');
		}
		# -------------------------------------------------------
		/**
		 * Override checkStatus() to return true - the statisticsViewerPlugin always initializes ok... (part to complete)
		 */
		public function checkStatus() {
			return array(
				'description' => $this->getDescription(),
				'errors' => array(),
				'warnings' => array(),
				'available' => ((bool)$this->opo_config->get('enabled'))
			);
		}
		# -------------------------------------------------------
		/**
		 * Insert activity menu
		 */
		public function hookRenderMenuBar($pa_menu_bar) {
			if ($o_req = $this->getRequest()) {
				// Hide the menu entry from users the controller would turn away,
				// rather than letting them click through to an access error. Same
				// rule as the controllers: an explicit role grant, or default_access.
				if (!$o_req->user->canDoAction('can_use_book_editor_plugin')
					&& !(bool)$this->opo_config->get('default_access')) {
					return $pa_menu_bar;
				}

				// The book list is the entry point, not the section editor: the
				// plugin now handles several books, so landing straight in the
				// sections of one of them would presuppose which.
				$default_menu_action = array(
					'displayName' => IdC::_t('PDF Book'),
					"default" => array(
						'module' => 'bookCreator',
						'controller' => 'Books',
						'action' => 'Index'
					),
					// Une seule entree, et elle est obligatoire : getHTMLMenuBar()
					// rend toujours le libelle de premier niveau en <a href='#'>
					// et n'utilise jamais la cle 'default' de ce niveau. C'est
					// donc ce sous-menu qui porte le seul lien cliquable.
					//
					// L'entree « Editeur » qui suivait pointait sur
					// bookCreator/Editor/Index, dont le corps se reduit a un
					// redirect vers Books/Index : deux libelles pour la meme
					// page, au prix d'un aller-retour HTTP. Le controleur Editor
					// reste en place, il porte BookSections, Summary,
					// EditSection et le reste ; seul le doublon de menu part.
					'navigation' => array(
						"Books" => array(
							'displayName' => IdC::_t('Books'),
							"default" => array(
								'module' => 'bookCreator',
								'controller' => 'Books',
								'action' => 'Index'
							)
						)
					)
				);
				$pa_menu_bar['bookCreator_menu'] =
					$default_menu_action
				;
			}
			
			return $pa_menu_bar;
		}

		public function hookRenderWidgets($pa_widgets_config)
		{
			$pa_widgets_config["bookCreatorEditorInfo"] = array(
				"domain" => array(
					"module" => "bookCreator",
					"controller" => "Editor"),
				"handler" => array(
					"module" => "bookCreator",
					"controller" => "Editor",
					"action" => 'Info',
					"isplugin" => true),
				"requires" => array(),
				"parameters" => array()
			);
			return $pa_widgets_config;
		}
		# -------------------------------------------------------
		/**
		 * Add plugin user actions
		 */
		static function getRoleActionList() {
			// Méthode statique : elle peut en théorie être atteinte sans qu'une
			// instance du plugin ait été construite, donc sans qu'IdC ait été
			// chargée. Les chemins connus passent tous par initPlugins(), mais la
			// garde coûte une ligne et évite une erreur fatale si cela changeait.
			if (!class_exists('IdC', false)) {
				require_once(__DIR__.'/lib/IdC.php');
				IdC::registerFile('bookCreator', __DIR__.'/conf/bookCreator_translations.conf');
			}
			return array(
				'can_use_book_editor_plugin' => array(
						'label' => IdC::_t('Can use book creator functions'),
						'description' => IdC::_t('User can use all use book creator functions.')
					)
			);
		}
		
	}
?>