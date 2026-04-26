<?php
/**
 * EtatDesCollectionsController
 * 
 * Controller for the etat_des_collections plugin
 */

class EtatDesCollectionsController extends ActionController {

    /**
     * Index action - displays the etat_des_collections_popup_html view
     */
    public function Index() {
		// get the id parameter from the request
		$id = $this->request->getParameter('id', pInteger);
		$this->view->setVar('id', $id);

        return $this->render('etat_des_collections_popup_html.php');
    }

	public function Update() {
		$id = $this->request->getParameter('id', pInteger);
		$etat_collections_revue = $this->request->getParameter('etat_collections_revue', pString);
		//print $etat_collections_revue;

		$vt_object = new ca_objects($id);
		if(!$vt_object->getPrimaryKey()) {
			die("Object not found");
		}
		$vt_object->setMode(ACCESS_WRITE);
		$current_etat_collections_revue = $vt_object->getWithTemplate("^ca_objects.etat_collections_revue");
		if($current_etat_collections_revue != $etat_collections_revue) {
			$vt_object->removeAttributes("etat_collections_revue");
			$vt_object->addAttribute(["etat_collections_revue" => $etat_collections_revue], "etat_collections_revue");
			$vt_object->update();
		}
		$this->view->setVar('id', $id);
		return $this->render('etat_collections_revue_updated_html.php'); // You can create a simple view to confirm the update
	}
}