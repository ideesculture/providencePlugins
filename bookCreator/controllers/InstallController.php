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

require_once(__CA_LIB_DIR__.'/Configuration.php');
require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/BookSchemaManager.php');

/**
 * Creates or completes the two tables owned by the plugin.
 *
 * Reached by redirection from EditorController when the schema is not usable.
 * The plugin is self-contained: it never modifies the CollectiveAccess core
 * schema, and this controller only ever adds tables, columns and indexes.
 */
class InstallController extends ActionController {
	# -------------------------------------------------------
	protected $opo_config;      // plugin configuration file
	private $opo_schema;
	# -------------------------------------------------------

	public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
		parent::__construct($po_request, $po_response, $pa_view_paths);

		$this->opo_config = Configuration::load(
			__CA_APP_DIR__.'/plugins/bookCreator/conf/bookCreator.conf'
		);

		if (!$this->userCanUsePlugin()) {
			$this->response->setRedirect(
				$this->request->config->get('error_display_url')
				.'/n/3000?r='.urlencode($this->request->getFullUrlPath())
			);
			return;
		}

		$this->opo_schema = new BookSchemaManager();
	}

	/**
	 * Access check. The role action is granted when a role carries it; when no
	 * role does, default_access in bookCreator.conf decides. Shipped at 1, so a
	 * fresh install is usable without touching the CollectiveAccess roles.
	 */
	private function userCanUsePlugin() {
		if ($this->request->user->canDoAction('can_use_book_editor_plugin')) { return true; }
		return (bool)$this->opo_config->get('default_access');
	}

	# -------------------------------------------------------

	/** Shows what is missing and offers to apply it. */
	public function Index() {
		$state = $this->opo_schema->getState();

		$this->view->setVar('is_usable', $this->opo_schema->isUsable());
		$this->view->setVar('changes', $this->opo_schema->describeState($state));
		$this->view->setVar('applied', null);
		$this->render('install_html.php');
	}

	/** Applies the missing tables, columns and indexes, then reports. */
	public function Run() {
		$applied = $this->opo_schema->install();

		if (!$this->opo_schema->isUsable()) {
			// Something could not be applied: show the remaining work rather
			// than sending the user to an editor that would fail.
			$this->view->setVar('error', _t('The plugin tables could not be fully installed. Check the database user privileges.'));
			$this->view->setVar('changes', $this->opo_schema->describeState());
		} else {
			$this->view->setVar('notification', _t('The plugin tables are ready.'));
			$this->view->setVar('changes', []);
		}

		$this->view->setVar('is_usable', $this->opo_schema->isUsable());
		$this->view->setVar('applied', $applied);
		$this->render('install_html.php');
	}
}
