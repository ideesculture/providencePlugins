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

class SectionEditorAjaxController extends ActionController
{
	# -------------------------------------------------------
	protected $opo_config;        // plugin configuration file
	protected $opa_locales;

	# -------------------------------------------------------
	# Constructor
	# -------------------------------------------------------

	public function __construct(&$po_request, &$po_response, $pa_view_paths = null)
	{
		global $allowed_universes;

		parent::__construct($po_request, $po_response, $pa_view_paths);

// 			if (!$this->request->user->canDoAction('can_use_book_editor_plugin')) {
// 				$this->response->setRedirect($this->request->config->get('error_display_url').'/n/3000?r='.urlencode($this->request->getFullUrlPath()));
// 				return;
// 			}

		$this->opo_config = Configuration::load(__CA_APP_DIR__ . '/plugins/bookEditor/conf/bookEditor.conf');

	}

	public function addSection() {
		$type_id = $this->getRequest()->getActionExtra();
		// Action : adding a section inside a book
		// Need : - book_id, section_type

		var_dump($type_id);
		die();
	}
}