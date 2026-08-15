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

	// Lib : parsedown, markdown parser
	require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/parsedown/Parsedown.php');
	require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/parsedown-extra/ParsedownExtra.php');

	// Lib : h2p (phantomjs)
	require_once(__CA_APP_DIR__.'/plugins/bookCreator/lib/h2p/autoloader.php');
	use H2P\Converter\PhantomJS;
	use H2P\TempFile;

	// Lib : phpOffice phpPresentation
	require_once __CA_APP_DIR__.'/plugins/bookCreator/lib/PHPPresentation-master/src/PhpPresentation/Autoloader.php';
	\PhpOffice\PhpPresentation\Autoloader::register();

	require_once __CA_APP_DIR__.'/plugins/bookCreator/lib/Common-master/src/Common/Autoloader.php';
	\PhpOffice\Common\Autoloader::register();

	define("__CA_BOOKEDITOR__SECTION_TEXT__", 1);
	define("__CA_BOOKEDITOR__SECTION_SET__", 2);

//error_reporting(E_ALL);
//ini_set("display_errors",true);

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
 			global $allowed_universes;
 			
 			parent::__construct($po_request, $po_response, $pa_view_paths);
 			
// 			if (!$this->request->user->canDoAction('can_use_book_editor_plugin')) {
// 				$this->response->setRedirect($this->request->config->get('error_display_url').'/n/3000?r='.urlencode($this->request->getFullUrlPath()));
// 				return;
// 			}

		    // We need the entire full path for PDF rendering
		    $this->path = "https://".__CA_SITE_HOSTNAME__.__CA_URL_ROOT__."/app/plugins/bookCreator/";
		    $this->dir = __CA_BASE_DIR__."/app/plugins/bookCreator/";

		    $this->opo_config = Configuration::load($this->dir.'conf/bookEditor.conf');

 		}

 		# -------------------------------------------------------
 		# Local functions
 		# -------------------------------------------------------


 		# -------------------------------------------------------
 		# Functions to render views
 		# -------------------------------------------------------
 		public function Index($type="") {

		    $o_data = new Db();
		    $qr_result = $o_data->query("
			    SELECT * 
			    FROM plugin_books 
			 ");
		    $va_search_result = array();


		    $vt_book = new plugin_books(1);
			$this->render('index_html.php');
 		}

 		public function ListBooks() {

	    }
 		# -------------------------------------------------------
	    public function BookSections() {
		    $book_id = ($this->request->getParameter("book", pInteger));
		    //var_dump($book_id);
		    $vt_book = new plugin_books($book_id);
	    	if($_POST && ($_POST["_formName"]=="sortBookSections")) {
	    		// We have new positions to sort sections
	    		$data=array();
	    		foreach($_POST["sort"] as $section_id=>$position) {
	    			// Create an array with booksection_id and new sort order
	    			$data[]=array("booksection_id"=>$section_id, "sort"=>$position["currposition"]);
			    }
			    // Call the method to sort sections
				$vt_book->sortSections($data);
		    }
		    $va_sections = $vt_book->getSections();
		    $vn_nb_pages = $vt_book->getNbPages();
		    $this->view->setVar("book_id", $book_id);
		    $this->view->setVar("sections", $va_sections);
		    $this->view->setVar("nb_pages", $vn_nb_pages);
		    $this->render('sections_html.php');
	    }

 		# -------------------------------------------------------
	    public function Summary() {
		    $book_id = ($this->request->getParameter("book", pInteger));
		    $vt_book = new plugin_books($book_id);
	    	if($_POST && ($_POST["_formName"]=="sortBookSections")) {
	    		// We have new positions to sort sections
	    		$data=array();
	    		foreach($_POST["sort"] as $section_id=>$position) {
	    			// Create an array with booksection_id and new sort order
	    			$data[]=array("booksection_id"=>$section_id, "sort"=>$position["currposition"]);
			    }
			    // Call the method to sort sections
				$vt_book->sortSections($data);
		    }
		    $va_sections = $vt_book->getSections();
		    $vn_nb_pages = $vt_book->getNbPages();
		    $this->view->setVar("book_id", $book_id);
		    $this->view->setVar("sections", $va_sections);
		    $this->view->setVar("nb_pages", $vn_nb_pages);
		    $this->render('summary_html.php');
	    }

	    public function EditSection() {
		    $book_id = ($this->request->getParameter("book", pInteger));
		    $section_id = ($this->request->getParameter("section", pInteger));

		    $vt_book = new plugin_books($book_id);
		    $va_section_info = $vt_book->getSection($section_id);

		    $this->view->setVar("book", $book_id);
		    $this->view->setVar("section", $section_id);
		    $this->view->setVar("section_details", $va_section_info);
		    switch($va_section_info["sectiontype"]) {
			    case __CA_BOOKEDITOR__SECTION_TEXT__:
				    $this->render('section_text_editor_html.php');
				    break;
			    case __CA_BOOKEDITOR__SECTION_SET__:
				    $this->render('section_set_editor_html.php');
				    break;
		    }
	    }
        public function SaveSection() {
        	$book_id = $this->request->getParameter("book", pInteger);
	        $section_id = $this->request->getParameter("section", pInteger);
	        $vt_book = new plugin_books($book_id);
	        $result = $vt_book->setSection($section_id, $_POST);
			if(is_array($result)) {
				$this->view->setVar("error", implode(" – ", $result));
			} else {
				$this->view->setVar("notification", "Section saved.");
			}
	        $va_section_info = $vt_book->getSection($section_id);

	        $this->view->setVar("book", $book_id);
	        $this->view->setVar("section", $section_id);
	        $this->view->setVar("section_details", $va_section_info);
	        switch($va_section_info["sectiontype"]) {
		        case __CA_BOOKEDITOR__SECTION_TEXT__:
			        $this->render('section_text_editor_html.php');
			        break;
		        case __CA_BOOKEDITOR__SECTION_SET__:
			        $this->render('section_set_editor_html.php');
			        break;
	        }
        }

        public function addSection() {
	        $book_id = ($this->request->getParameter("book", pInteger));
	        //var_dump($book_id);
	        $vt_book = new plugin_books($book_id);
	        $result = $vt_book->addSection();
	        if(is_array($result)) {
		        $this->view->setVar("error", implode(" – ", $result));
	        } else {
		        $this->view->setVar("notification", "Section added.");
	        }

	        $va_sections = $vt_book->getSections();
	        $this->view->setVar("sections", $va_sections);
	        $this->view->setVar("book_id", $book_id);

	        $this->render('sections_html.php');
        }

        public function deleteSection() {
	        $book_id = $this->request->getParameter("book", pInteger);
	        $section_id = $this->request->getParameter("section", pInteger);
	        $vt_book = new plugin_books($book_id);
	        if(!$_POST) {
		        $this->view->setVar("book", $book_id);
		        $this->view->setVar("section", $section_id);
		        // confirmation dialog
		        $this->render('section_delete_confirm_html.php');
			} else {
				// confirmed, deletion
		        $result = $vt_book->deleteSection($section_id);
		        if(is_array($result)) {
			        $this->view->setVar("error", implode(" – ", $result));
		        } else {
			        $this->view->setVar("notification", "Section saved.");
		        }
		        $this->view->setVar("book", $book_id);
		        $this->view->setVar("section", $section_id);
		        // confirmation dialog
		        $this->render('section_deleted_html.php');
	        }
        }
	    private function generateHTML($book_id, $section_id=null, $is_for_pdf=false) {
		    // Initialize Markdown parser
		    $Parsedown = new ParsedownExtra();

		    // Load book informations
		    $vt_book = new plugin_books($book_id);
			$__dir = __CA_APP_DIR__."/plugins/bookCreator/";
			$__path = __CA_URL_ROOT__."/".str_replace(__CA_BASE_DIR__, "", $__dir);

		    // Loading styles
		    $result = "<head><meta charset=\"UTF-8\" />";
			if($is_for_pdf) {
				$result .= "<link rel=\"stylesheet\" href=\"".$__dir."assets/css/pdf.css\" type=\"text/css\" media=\"print,screen\">";
			} else {
				$result .= "<link rel=\"stylesheet\" href=\"".$__path."assets/css/pdf.css\" type=\"text/css\" media=\"print,screen\">";
			}
		    $result .= "</head><body>";

			// Loading content
		    foreach($vt_book->getSections() as $section) {
			    if(($section_id === null) || ($section_id == $section["booksection_id"])) {
					    
				    $content = $Parsedown->text($section["content"]);
				    if (strpos($section["style"],"chapter") > -1 ) {
					    $content = "<h1>".$section["title"]."</h1><div class='content'>".$content."</div>";
				    }
				    if ($section["style"] == "page-de-titre") {
					    $content = preg_replace("/Editeur : (.*)/\n", "<div class=\"bottom\">$1</div>\n", $content);
				    }
				    if (strpos($section["style"],"hors-texte") > -1 ) {
					    if($section["representation_id"]) {
						    $vt_representation = new ca_object_representations($section["representation_id"]);
						    $vs_media_url = $vt_representation->getMediaUrl("media","page");
						    $content = "<div class='media'><img src=\"".$vs_media_url."\" /><p>".$vt_representation->get("preferred_labels")."</p></div><div class=\"content\">".$content."</div>";
					    }
				    }
	
				    if (strpos($section["style"],"one-image") > -1 ) {
					    if($section["representation_id"]) {
						    $vt_representation = new ca_object_representations($section["representation_id"]);
						    $vs_media_url = $vt_representation->getMediaUrl("media","page");
						    $content = "<div class=\"content\"><div class='mediacolumn'><img src=\"".$vs_media_url."\" /><div>".$vt_representation->get("preferred_labels")."</div></div><div class=\"content-text\">".$content."</div></div>";
					    }
				    }
				    if (strpos($section["style"],"page-image") > -1 ) {
					    if($section["representation_id"]) {
						    $vt_representation = new ca_object_representations($section["representation_id"]);
						    $vs_media_url = $vt_representation->getMediaUrl("media","book");
						    $content = "<div class=\"content\">".$content."<div><img src=\"".$vs_media_url."\" /></div></div>";
					    }
				    }			    
				    if ((strpos($section["style"],"ensemble") > -1 )) {
					    if($section["set_id"]) {
						    $vt_set = new ca_sets($section["set_id"]);
						    error_reporting(E_ERROR);
							ini_set("display_errors", true);
						    $va_row_ids = array_keys($vt_set->getItemRowIDs());
						    for($i=0;$i<sizeof($va_row_ids);$i++) {
							    $object_id = $va_row_ids[$i];
							    $vt_object = new ca_objects($object_id);
							    
							    $vt_representation = new ca_object_representations($vt_object->getPrimaryRepresentationID());
								
							    $vs_media_url = $vt_representation->getMediaUrl("media","page");
							    $vs_media_filename = $vt_representation->get("ca_object_representations.original_filename");

								$vt_block_type = "normal";

								$width = $vt_object->get("ca_objects.work_dimensions.dimensions_width");
							    $depth = $vt_object->get("ca_objects.work_dimensions.dimensions_depth");
							    $height = $vt_object->get("ca_objects.work_dimensions.dimensions_height");
							    
							    $inner_content = "<p class='paragraph-titre'><span class='idno'>".ucfirst($vt_object->get("ca_objects.idno"))."</span> <span class='name'>".$vt_object->get("ca_objects.preferred_labels.name")."</span></p>
							    <p class='mat_et_techniques'>".ucfirst($vt_object->get("ca_objects.mat_et_techniques_txt"))."</p>
							    <p class='inscriptions'>".ucfirst($vt_object->getWithTemplate("^ca_objects.inscriptions.inscription_type ^ca_objects.inscriptions.inscription_transcription ^ca_objects.inscriptions.inscription_place "))."</p>";
							    if($width || $height || $depth) {
								    $inner_content .= "<p class='dimensions'>"."L ".$width." x H ".$height.($depth ? " x P ".$depth : "")."</p>";
							    }
							    $inner_content .= "<p class='description'>".ucfirst($vt_object->get("ca_objects.description"))."</p>";
															    
															
								/*if($vs_media_filename == "Illustration-Photo-manquante2.jpg") {
									$vt_block_type = "no-image";
							    	$next_object_id = $va_row_ids[$i+1];
									$vt_next_object = new ca_objects($next_object_id);
								    $vt_next_representation = new ca_object_representations($vt_next_object->getPrimaryRepresentationID());
								    $vs_next_media_url = $vt_next_representation->getMediaUrl("media","page");
								    $vs_next_media_filename = $vt_next_representation->get("ca_object_representations.original_filename");
								    
								    if($vs_next_media_filename=="Illustration-Photo-manquante2.jpg") {
									    $vt_block_type = "twin-block-no-image";
									    
									    // TODO : factorize !!!
										$width2 = $vt_next_object->get("ca_objects.work_dimensions.dimensions_width");
									    $depth2 = $vt_next_object->get("ca_objects.work_dimensions.dimensions_depth");
									    $height2 = $vt_next_object->get("ca_objects.work_dimensions.dimensions_height");
									    $inner_content2 = "<p class='paragraph-titre'><span class='idno'>".ucfirst($vt_next_object->get("ca_objects.idno"))."</span> <span class='name'>".$vt_next_object->get("ca_objects.preferred_labels.name")."</span></p>
									    <p class='mat_et_techniques'>".ucfirst($vt_next_object->get("ca_objects.mat_et_techniques_txt"))."</p>
									    <p class='inscriptions'>".ucfirst($vt_next_object->getWithTemplate("^ca_objects.inscriptions.inscription_type ^ca_objects.inscriptions.inscription_transcription ^ca_objects.inscriptions.inscription_place "))."</p>";
									    if($width2 || $height2 || $depth2) {
										    $inner_content2 .= "<p class='dimensions'>"."L ".$width2." x H ".$height2.($depth2 ? " x P ".$depth2 : "")."</p>";
									    }
									    $inner_content2 .= "<p class='description'>".ucfirst($vt_next_object->get("ca_objects.description"))."</p>";
		

								    }
							    }*/


							    // We have an image content
							    if($vt_block_type =="normal") {
								    $content .= "<div class='media'>";
								    $content .= "<div class='image' style='background-image:url(\"".$vs_media_url."\");'></div>";
									$content .= $inner_content."</div>";
							    } elseif($vt_block_type == "twin-block-no-image") {
								    // We have 2 non-image contents
								    $content .= "<div class='media'><div style='margin-top:10mm;'>";
									$content .= $inner_content."</div><div style='margin-top:6mm;'>".$inner_content2."</div></div>";
									$i++;
							    } else {
								    // We have a non-image content
								    $content .= "<div class='media'>";
									$content .= $inner_content."</div>";
							    }
						    }
					    }
				    }
				    if (strpos($section["style"],"deux-images-droite") > -1 ) {
						$content = "<div class=\"content\">".$content."</div>";
						$content .= "<div class=\"media-bar\">";
					    if($section["set_id"]) {
						    $vt_set = new ca_sets($section["set_id"]);
						    foreach(array_keys($vt_set->getItemRowIDs()) as $representation_id) {
							    $vt_representation = new ca_object_representations($representation_id);
							    $vs_media_url = $vt_representation->getMediaUrl("media","page");
							    $content .= "<div class='media'><div class='image' style='background-image:url(\"".$vs_media_url."\");'></div><p class='paragraph-titre'><span class='name'>".$vt_representation->get("ca_object_representations.preferred_labels.name")."</span></p>";
							    $content .= "</div>";
						    }
					    }
					    $content .= "</div>";
				    }			    
				    if (strpos($section["style"],"deux-images-gauche") > -1 ) {
					    $text = $content;
						$content = "<div class=\"media-bar\">";
					    if($section["set_id"]) {
						    $vt_set = new ca_sets($section["set_id"]);
						    foreach(array_keys($vt_set->getItemRowIDs()) as $representation_id) {
							    $vt_representation = new ca_object_representations($representation_id);
							    $vs_media_url = $vt_representation->getMediaUrl("media","page");
							    $content .= "<div class='media'><div class='image' style='background-image:url(\"".$vs_media_url."\");'></div><p class='paragraph-titre'><span class='name'>".$vt_representation->get("ca_object_representations.preferred_labels.name")."</span></p>";
							    $content .= "</div>";
						    }
					    }
					    $content .= "</div>";
						$content .= "<div class=\"content\">".$text."</div>";
				    }			    
				    $result.= "<div class=\"".$section["style"]."\">".$content."</div>\n";
				    $result.= "<div style=\"clear:both;\"></div>\n";
			    
				}
			}
		    $result .= "</body>";
		    return $result;
	    }

		public function renderHTML() {
			$book_id = ($this->request->getParameter("book", pInteger));
			$result = $this->generateHTML($book_id, null, false);
			print $result;
			die();
		}
        public function renderPDF() {
	        error_reporting(E_ERROR);
	        ini_set("display_errors", true);
	        
	        $book_id = ($this->request->getParameter("book", pInteger));
	        $result = $this->generateHTML($book_id, null, true);

	        // Rendering with wkhtmltopdf (mandatory)
			file_put_contents($this->dir."tmp/pdf-content_".$book_id.".html", $result);
			//var_dump($this->dir."tmp/pdf-content.html");
			//var_dump($this->dir."tmp/output.pdf");
			//die();
        	// $cmd = "/usr/local/bin/wkhtmltopdf --zoom 1.57 -T 0 -B 0 -L 0 -R 0 -O landscape -s A4 ".$this->dir."tmp/pdf-content.html ".$this->dir."tmp/output.pdf";
			// exec($cmd, $output);

	        // Rendering with PhantomJS (alternative)
	        $input = new TempFile($result, 'html'); // Make sure the 2nd parameter is 'html'
	        $converter = new PhantomJS(array(
		        // You should use 'search_paths' when you want to point the phantomjs binary to somewhere else
		        // 'search_paths' => shell_exec('which phantomjs'),
		        'orientation' => PhantomJS::ORIENTATION_LANDSCAPE,
		        'format' => PhantomJS::FORMAT_A4,
		        'zoomFactor' => 0.4,
		        'border' => '1cm',
		        //'header' => array(
			    //    'height' => '0.3cm',
			    //    'content' => "<span style='font-size:6pt;'>Catalogue raisonné Louis Floutier</span>",
		        //),
		        'footer' => array(
			        'height' => '0.3cm',
			        'content' => "",
		        )
	        ));
	        $converter->convert($input, $this->dir."tmp/output_".$book_id.".pdf");
	        //if($output === array()) {
	        if(is_file($this->dir."tmp/output_".$book_id.".pdf")) {
	        	// No problem, so showing pdf
		        $cmd = "/usr/local/bin/pdftk ".$this->dir."assets/covers/couverture.pdf ".$this->dir."tmp/output_".$book_id.".pdf ".$this->dir."assets/covers/4e-couverture.pdf cat output ".$this->dir."tmp/book_".$book_id.".pdf";
		        //$result = exec($cmd, $output);
		        if($output === array() or is_null($output)) {
			        $this->view->setVar("book", $book_id);
			        $this->view->setVar("file", __CA_APP_DIR__.'/plugins/bookCreator/tmp/book_'.$book_id.'.pdf');
					$this->view->setVar("filename", 'book_'.$book_id.'.pdf');
			        $this->render('view_pdf_html.php');
		        } else {
			        var_dump($output);
			        die("hein ?");
		        }
		        die();
	        }
        }

		public function renderSectionsPDF() {
			ini_set("display_errors", false);
			ini_set("timeout", 0);
			ini_set("memory_limit", "4000M");
			$book_id = ($this->request->getParameter("book", pInteger));
		    //var_dump($book_id);
		    $vt_book = new plugin_books($book_id);
		    $va_sections = $vt_book->getSections();
			
		    $this->view->setVar("book_id", $book_id);
		    $this->view->setVar("sections", $va_sections);
			$vn_limit = ($this->request->getParameter("limit", pInteger));
			$files = array();
			$i=0;
		    foreach($va_sections as $va_section) {
			    //var_dump($va_section);
			    if(($section_id = $va_section["booksection_id"]) > 0) {
				    //var_dump($va_section["booksection_id"]);
				    $file = $this->dir."tmp/output_".$book_id."_".$section_id.".pdf";
				    $files[] = $file;
				    $delta = time() - filemtime($file);
				    if(!is_file($file)) {
					    $this->renderSectionPDF($book_id, $section_id, 1);
				    }
				    if((time() - filemtime($file)) > 1800) {
					    $this->renderSectionPDF($book_id, $section_id, 1);
				    }
				    echo "Section $section_id traitée<br/>\n";
					ob_flush();
					flush();
				    if($vn_limit>0 && $i==$vn_limit) {
					    break;
				    }
			    }
			    $i++;
		    }

			$target_file = $this->dir."tmp/book_".$book_id.".pdf";
		    $file_deleted = unlink($target_file);
		    if(is_file($target_file) && !$file_deleted) {
			    print "Impossible de supprimer la dernière génération.\n";
			    die();
			};

	        $cmd1 = "/usr/local/bin/pdftk ".implode(" ",$files)." cat output ".$target_file;
			$result = exec($cmd1, $output);
			echo "Contenu généré<br/>\n";
			ob_flush();
			flush();
			
			$target_paged_file = $this->dir."tmp/book_".$book_id."_paged.pdf";
		    $file_deleted = unlink($target_paged_file);
		    if(is_file($target_paged_file) && !$file_deleted) {
			    print "Impossible de supprimer la dernière génération (avec pagination).\n";
			    die();
			};
			
			$cmd2 = 'cd '.__CA_APP_DIR__.'/plugins/bookCreator/tmp && ../lib/cpdf/cpdf -add-text "%Page" -bottom 20pt -font "Times-Roman" -font-size 10 '.$target_file.' -o '.$target_paged_file;
			$result = exec($cmd2, $output);
			echo "Pagination ajoutée<br/>\n";
			ob_flush();
			flush();
					    
		    $target_file_with_cover = $this->dir."tmp/book_".$book_id."_with_cover.pdf";
		    $file_deleted = unlink($target_file_with_cover);
		    if(is_file($target_file_with_cover) && !$file_deleted) {
			    print "Impossible de supprimer la dernière génération (avec couvertures).\n";
			    die();
			};
		    
	        $cmd3 = "/usr/local/bin/pdftk ".$this->dir."assets/covers/couverture.pdf ".$target_paged_file." ".$this->dir."assets/covers/4e-couverture.pdf cat output ".$target_file_with_cover;
			echo "Couverture ajoutée<br/>\n";
			ob_flush();
	        flush();
	        
	        $result = exec($cmd3, $output);
	        //$target_file = $this->dir."tmp/book_".$book_id.".pdf";
	        print "<a href='".__CA_URL_ROOT__."/app/plugins/bookCreator/tmp/book_".$book_id."_with_cover.pdf'>Le livre a été généré</a>";
	        die();
	        /*if($output === array() or is_null($output)) {
		        $this->view->setVar("book", $book_id);
		        $this->view->setVar("file", __CA_APP_DIR__.'/plugins/bookCreator/tmp/book_'.$book_id.'.pdf');
				$this->view->setVar("filename", 'book_'.$book_id.'.pdf');
		        $this->render('view_pdf_html.php');
	        } else {
		        var_dump($output);
		        die("hein ?");
	        }*/
		}

        public function renderSectionPDF($book_id=null, $section_id=null, $dont_show=null) {
			
	        error_reporting(E_ERROR);
	        ini_set("display_errors", true);
	        
	        if(!isset($book_id)) {
		        $book_id = ($this->request->getParameter("book", pInteger));
		    }
	        if(!isset($section_id)) {
		        $section_id = ($this->request->getParameter("section", pInteger));
		    }
			if(!isset($vb_screen)) {
		        $vb_screen = ($this->request->getParameter("screen", pInteger)) > 1;
		    }
	        $debug = ( ($this->request->getParameter("debug", pInteger))*1 == 1);
	        
			if($debug) {
				$result = $this->generateHTML($book_id, $section_id, false);
		        print $result;
		        die();
	        }
			$result = $this->generateHTML($book_id, $section_id, true);
			$result = str_replace(__CA_SITE_PROTOCOL__."://".__CA_SITE_HOSTNAME__.__CA_URL_ROOT__, __CA_BASE_DIR__, $result);
			file_put_contents($this->dir."tmp/pdf-content_".$book_id."_".$section_id.".html", $result);
			//sleep(1);

			$input = new TempFile($result, 'html'); // Make sure the 2nd parameter is 'html'
	        $temp_file = $input->getFileName();

	        $phantomJS_config = array(
		        'orientation' => PhantomJS::ORIENTATION_LANDSCAPE,
		        'format' => PhantomJS::FORMAT_A4,
		        'zoomFactor' => 0.4,
		        'border' => '1cm'
		    );
	        // Load book informations
		    $vt_book = new plugin_books($book_id);
		    $va_section = $vt_book->getSection($section_id);
		    
		    // Don't add header on chapter title pages, but on other pages
		    $vb_section_is_chapter_page = (strpos($va_section["style"], "chapter") !== false);
		    if(!$vb_section_is_chapter_page) {
			    $phantomJS_config["header"] = array(
			        'height' => '0.7cm',
			        'content' => "",
		        );
		    }
			unlink($this->dir."tmp/output_".$book_id."_".$section_id.".pdf");
			//$cmd ="google-chrome --no-sandbox --headless --run-all-compositor-stages-before-draw --disable-gpu --disable-dev-shm-usage --print-to-pdf='".$this->dir."tmp/output_".$book_id."_".$section_id.".pdf"."' --no-pdf-header-footer ".$this->dir."tmp/pdf-content_".$book_id."_".$section_id.".html";
			$cmd ="wkhtmltopdf -L 0 -R 0 --page-width 297mm --page-height 210mm --disable-smart-shrinking --enable-local-file-access ".$this->dir."tmp/pdf-content_".$book_id."_".$section_id.".html ".$this->dir."tmp/output_".$book_id."_".$section_id.".pdf";
			exec($cmd, $result);
			//var_dump($cmd);
			//die();

		    if($dont_show === 1) {
			    return true;
			}
	        if(is_file($this->dir."tmp/output_".$book_id."_".$section_id.".pdf")) {
		        $result = exec("/usr/bin/pdfinfo ".$this->dir."tmp/output_".$book_id."_".$section_id.".pdf | grep Pages", $result2);
		        $nb_pages = str_replace("Pages:","",$result)*1;
		        $is_field_page_updated = $vt_book->setSection($section_id, ["pages"=>$nb_pages]);
		        if(!$is_field_page_updated) {
			        var_dump("Unable to update number of pages for this section");
			        die();
		        }
				// No problem, so showing pdf
		        $this->view->setVar("file", __CA_APP_DIR__.'/plugins/bookCreator/tmp/output_'.$book_id."_".$section_id.".pdf");
		        $this->view->setVar("url", __CA_URL_ROOT__.'/app/plugins/bookCreator/tmp/output_'.$book_id."_".$section_id.".pdf");
				$this->view->setVar("filename", 'output_'.$book_id."_".$section_id.".pdf");
				//var_dump('book_'.$book_id."_".$section_id.".pdf");
				
				$this->render('link_pdf_html.php');
				//die();
	        } else {
		        var_dump($cmd);
		        var_dump($result);
		        die("dough");
	        }
	        die();
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