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

 	require_once(__CA_LIB_DIR__.'/TaskQueue.php');
 	require_once(__CA_LIB_DIR__.'/Configuration.php');
 	require_once(__CA_MODELS_DIR__.'/ca_lists.php');
 	require_once(__CA_MODELS_DIR__.'/ca_sets.php');
 	require_once(__CA_MODELS_DIR__.'/ca_locales.php');
	require_once(__CA_APP_DIR__.'/plugins/bookCreator/models/plugin_books.php');
	require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/BookSchemaManager.php');
	require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/TemplateRegistry.php');
	require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/BookCsrf.php');



 	class EditorController extends ActionController {
 		# -------------------------------------------------------
  		protected $opo_config;		// plugin configuration file
 		protected $opa_locales;
	    protected $path;
	    protected $dir;

 		# -------------------------------------------------------
 		# Constructor
 		# -------------------------------------------------------

 		public function __construct(&$po_request, &$po_response, $pa_view_paths=null) {
 			parent::__construct($po_request, $po_response, $pa_view_paths);

		    // We need the entire full path for PDF rendering
		    $this->path = "https://".__CA_SITE_HOSTNAME__.__CA_URL_ROOT__."/app/plugins/bookCreator/";
		    $this->dir = __CA_BASE_DIR__."/app/plugins/bookCreator/";

		    $this->opo_config = Configuration::load($this->dir.'conf/bookCreator.conf');

			if (!$this->userCanUsePlugin()) {
				$this->response->setRedirect(
					$this->request->config->get('error_display_url')
					.'/n/3000?r='.urlencode($this->request->getFullUrlPath())
				);
				return;
			}

			// The plugin owns its tables and installs them itself: send the user
			// to the installer rather than failing later on a missing column.
			$o_schema = new BookSchemaManager();
			if (!$o_schema->isUsable()) {
				$this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Install', 'Index'));
				return;
			}
 		}

 		/**
 		 * Layouts the book can actually use, keyed by code.
 		 *
 		 * Filtered by the page format of the book: a layout is calibrated for a
 		 * format, it does not adapt to it, so offering a six-per-page grid on a
 		 * 21x21 booklet would only produce an overflowing page. Falls back to the
 		 * thumbnails on disk while a theme has no manifests yet, which keeps the
 		 * editor usable during the migration.
 		 */
 		private function availableTemplates($vt_book) {
 			$theme  = $vt_book->getField('theme', 'default');
 			$format = $vt_book->getField('page_format', 'a4-landscape');

 			$registry  = new TemplateRegistry($theme);
 			$templates = $registry->getTemplates($format);
 			if (sizeof($templates)) { return $templates; }

 			$legacy = [];
 			foreach (glob($this->dir.'assets/styles/*.png') as $thumbnail) {
 				$code = basename($thumbnail, '.png');
 				$legacy[$code] = ['code' => $code, 'label' => $code];
 			}
 			return $legacy;
 		}

 		/**
 		 * Whether this request may change anything.
 		 *
 		 * Saving, adding and deleting a section were all reachable without a
 		 * token, and adding by a plain link: an image tag on any page a
 		 * logged-in editor visited was enough to append a section to a book.
 		 */
 		private function isTrustedRequest() {
 			return $this->request->isLoggedIn() && BookCsrf::isValid($this->request);
 		}

 		/**
 		 * Access check. The role action is granted when a role carries it; when
 		 * no role does, default_access in bookCreator.conf decides. Shipped at 1,
 		 * so a fresh install is usable without configuring roles first.
 		 */
 		private function userCanUsePlugin() {
 			if ($this->request->user->canDoAction('can_use_book_editor_plugin')) { return true; }
 			return (bool)$this->opo_config->get('default_access');
 		}

 		# -------------------------------------------------------
 		# Local functions
 		# -------------------------------------------------------


 		# -------------------------------------------------------
 		# Functions to render views
 		# -------------------------------------------------------
 		/**
 		 * Entry point of the module.
 		 *
 		 * Redirects to the book list rather than rendering a page of its own:
 		 * the v1 landing page listed two books by hand-written links, on a URL
 		 * root belonging to one installation, and there is now a real dashboard.
 		 */
 		public function Index($type="") {
			$this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Books', 'Index'));
 		}

 		# -------------------------------------------------------
	    public function BookSections() {
		    $book_id = ($this->request->getParameter("book", pInteger));
		    $vt_book = new plugin_books($book_id);
		    if (!$vt_book->isLoaded()) {
			    $this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Books', 'Index'));
			    return;
		    }

	    	// Reordering writes to the database, so it takes a token like every
	    	// other write. The keys are checked before being read: a forged post
	    	// without them reached foreach() on a missing key, a fatal in PHP 8.
	    	if(isset($_POST["_formName"]) && $_POST["_formName"]=="sortBookSections"
	    		&& isset($_POST["sort"]) && is_array($_POST["sort"])) {

	    		if (!$this->isTrustedRequest()) {
	    			$this->view->setVar("error", _t('Invalid or expired request. Reorder the sections and save again.'));
	    		} else {
	    			// We have new positions to sort sections
	    			$data=array();
	    			foreach($_POST["sort"] as $section_id=>$position) {
	    				// Create an array with booksection_id and new sort order
	    				$data[]=array("booksection_id"=>$section_id, "sort"=>$position["currposition"]);
				    }
				    // Call the method to sort sections. It reports a failed
				    // statement rather than throwing, and saying nothing here
				    // would show the old order back as if it had been saved.
				    $result = $vt_book->sortSections($data);
				    if (is_array($result)) {
				    	$this->view->setVar("error", implode(" – ", $result));
				    } else {
				    	$this->view->setVar("notification", _t("Order saved."));
				    }
	    		}
		    }
		    $va_sections = $vt_book->getSections();
		    $vn_nb_pages = $vt_book->getNbPages();
		    $this->view->setVar("book_id", $book_id);
		    $this->view->setVar("sections", $va_sections);
		    $this->view->setVar("nb_pages", $vn_nb_pages);
		    $this->view->setVar("book_title", $vt_book->getTitle());
		    $this->render('sections_html.php');
	    }

 		# -------------------------------------------------------
	    public function Summary() {
		    $book_id = ($this->request->getParameter("book", pInteger));
		    $vt_book = new plugin_books($book_id);
		    if (!$vt_book->isLoaded()) {
			    $this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Books', 'Index'));
			    return;
		    }

		    // Read only: summary_html.php shows the table of contents and carries
		    // no form. The reordering block that used to sit here was a copy of
		    // the one in BookSections(), waiting for a post that never comes.
		    $va_sections = $vt_book->getSections();
		    $vn_nb_pages = $vt_book->getNbPages();
		    $this->view->setVar("book_id", $book_id);
		    $this->view->setVar("sections", $va_sections);
		    $this->view->setVar("nb_pages", $vn_nb_pages);
		    $this->view->setVar("book_title", $vt_book->getTitle());
		    $this->render('summary_html.php');
	    }

	    public function EditSection() {
		    $book_id = ($this->request->getParameter("book", pInteger));
		    $section_id = ($this->request->getParameter("section", pInteger));

		    $vt_book = new plugin_books($book_id);
		    $va_section_info = $vt_book->getSection($section_id);

		    // A deleted book or section used to hand false to the view, which
		    // then indexed it: a form of empty fields and a string of PHP 8
		    // warnings, with nothing saying what had happened.
		    if (!$vt_book->isLoaded() || !is_array($va_section_info)) {
			    $this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Books', 'Index'));
			    return;
		    }

		    $this->view->setVar("book", $book_id);
		    $this->view->setVar("section", $section_id);
		    $this->view->setVar("section_details", $va_section_info);
		    $this->view->setVar("templates", $this->availableTemplates($vt_book));
		    // Single editor for every section type: it carries the set_id and
		    // representation_id fields used by the "set" layouts.
		    $this->render('section_text_editor_html.php');
	    }
        public function SaveSection() {
        	$book_id = $this->request->getParameter("book", pInteger);
	        $section_id = $this->request->getParameter("section", pInteger);
	        $vt_book = new plugin_books($book_id);
	        // An unchecked checkbox is simply absent from the payload, so the
	        // flag has to be forced here. It used to be forced inside the model,
	        // which meant every write that did not mention it — the worker
	        // recording page counts, for one — silently cleared it.
	        $post = $_POST;
	        if (!isset($post['is_in_summary'])) { $post['is_in_summary'] = 0; }

	        // A token expires when the session is renewed, which on a long piece
	        // of editing is exactly when it happens. Redirecting to the book list
	        // threw away the text that had just been typed and explained nothing.
	        // The editor comes back instead, still holding what was posted and
	        // carrying a fresh token, so saving again is one click.
	        if (!$this->isTrustedRequest()) {
		        $this->view->setVar("error", _t('Invalid or expired request. Save again.'));
		        $this->view->setVar("book", $book_id);
		        $this->view->setVar("section", $section_id);
		        $this->view->setVar("section_details", $this->mergePostedSection($vt_book->getSection($section_id), $post));
		        $this->view->setVar("templates", $this->availableTemplates($vt_book));
		        $this->render('section_text_editor_html.php');
		        return;
	        }

	        $result = $vt_book->setSection($section_id, $post);
			if(is_array($result)) {
				$this->view->setVar("error", implode(" – ", $result));
			} else {
				$this->view->setVar("notification", _t("Section saved."));
			}
	        $va_section_info = $vt_book->getSection($section_id);
	        // The section may have gone while it was being edited. getSection()
	        // then returns false, which the view indexed as an array: a form of
	        // empty fields, and the text just typed lost without a word. What was
	        // posted is shown back instead, so it can at least be copied out.
	        if (!is_array($va_section_info)) {
		        $va_section_info = $this->mergePostedSection($this->blankSection($book_id, $section_id), $post);
	        }


	        $this->view->setVar("book", $book_id);
	        $this->view->setVar("section", $section_id);
	        $this->view->setVar("section_details", $va_section_info);
	        $this->view->setVar("templates", $this->availableTemplates($vt_book));
	        $this->render('section_text_editor_html.php');
        }

        public function addSection() {
	        $book_id = ($this->request->getParameter("book", pInteger));
	        $vt_book = new plugin_books($book_id);

	        // Without this, an unknown book id inserted a row carrying it: a
	        // section belonging to no book, that no screen can ever reach.
	        if (!$vt_book->isLoaded()) {
		        $this->response->setRedirect(caNavUrl($this->request, 'bookCreator', 'Books', 'Index'));
		        return;
	        }

	        if (!$this->isTrustedRequest()) {
		        $this->view->setVar("error", _t('Invalid or expired request. Reload this page and try again.'));
	        } else {
		        $result = $vt_book->addSection();
		        if(is_array($result)) {
			        $this->view->setVar("error", implode(" – ", $result));
		        } else {
			        $this->view->setVar("notification", _t("Section added."));
		        }
	        }

	        // nb_pages and book_title as well: the view prints both, and leaving
	        // them out gave a heading with no book name and a page counter
	        // reading "12/" on every line.
	        $this->view->setVar("sections", $vt_book->getSections());
	        $this->view->setVar("book_id", $book_id);
	        $this->view->setVar("nb_pages", $vt_book->getNbPages());
	        $this->view->setVar("book_title", $vt_book->getTitle());

	        $this->render('sections_html.php');
        }

        /**
         * Overlays what a form posted on top of the stored section.
         *
         * Used when a save is refused: the editor is redisplayed with the text
         * as it was typed rather than as it is in the database, so nothing is
         * lost between the refusal and the retry. Only the fields the form owns
         * are taken from the payload.
         */
        private function mergePostedSection($section, $post) {
	        if (!is_array($section)) { return $section; }

	        foreach (['title', 'intro', 'content', 'style', 'set_id', 'representation_id', 'is_in_summary'] as $field) {
		        if (array_key_exists($field, $post)) { $section[$field] = $post[$field]; }
	        }
	        return $section;
        }

        /**
         * An empty section row, to hang a rejected payload on.
         *
         * Carries every column the editor view reads, so that showing a section
         * that no longer exists does not become a page of PHP warnings.
         */
        private function blankSection($book_id, $section_id) {
	        return [
		        'booksection_id' => (int)$section_id,
		        'book_id' => (int)$book_id,
		        'title' => '', 'intro' => '', 'content' => '', 'style' => '',
		        'set_id' => null, 'representation_id' => null, 'is_in_summary' => 0,
		        'sort' => 0, 'options' => null, 'pages' => null, 'first_page' => null,
		        'content_hash' => null, 'rendered_on' => null,
	        ];
        }

        public function deleteSection() {
	        $book_id = $this->request->getParameter("book", pInteger);
	        $section_id = $this->request->getParameter("section", pInteger);
	        $vt_book = new plugin_books($book_id);
	        if(!$_POST) {
		        // The confirmation screen writes nothing and therefore needs no
		        // token; requiring one on the link would only push it into the
		        // URL, where it lands in the access logs and in the history.
		        $this->view->setVar("book", $book_id);
		        $this->view->setVar("section", $section_id);
		        $this->view->setVar("section_details", $vt_book->getSection($section_id));
		        // confirmation dialog
		        $this->render('section_delete_confirm_html.php');
			} else {
				// confirmed, deletion — this is the step the token guards.
				// An expired token sends the editor back to the confirmation
				// with an explanation rather than to the section list: a silent
				// redirect reads as "it deleted nothing and said nothing", and
				// the natural response to that is to click again.
		        if (!$this->isTrustedRequest()) {
			        $this->view->setVar("book", $book_id);
			        $this->view->setVar("section", $section_id);
			        $this->view->setVar("section_details", $vt_book->getSection($section_id));
			        $this->view->setVar("error", _t('Invalid or expired request. Confirm again.'));
			        $this->render('section_delete_confirm_html.php');
			        return;
		        }
		        $result = $vt_book->deleteSection($section_id);
		        if(is_array($result)) {
			        $this->view->setVar("error", implode(" – ", $result));
		        } else {
			        $this->view->setVar("notification", _t("Section deleted."));
		        }
		        $this->view->setVar("book", $book_id);
		        $this->view->setVar("section", $section_id);
		        // confirmation dialog
		        $this->render('section_deleted_html.php');
	        }
        }
	    # -------------------------------------------------------
	    # Sidebar info handler
	    # -------------------------------------------------------
	    public function Info($pa_parameters)
	    {
		    $this->view->setVar('myvar', null);
		    return $this->render('widget_editor_html.php', true);
	    }
 	}
 ?>