<?php

class SaveAndStayPlugin extends BaseApplicationPlugin {

    public function __construct() {
        parent::__construct();
    }

    public function checkStatus() {
        return [
            'description' => "Rend la barre Enregistrer/Annuler fixe en haut de l'éditeur",
            'errors'      => [],
            'warnings'    => [],
            'available'   => true
        ];
    }

    public function hookAppendToEditorInspector(&$pa_params) {
		if(!isset($pa_params['caEditorInspectorAppend'])) {
			$pa_params['caEditorInspectorAppend'] = '';
		}
        $pa_params['caEditorInspectorAppend'] .= '
			<style>
			.control-box {
				position: sticky;
				top: 59px;
				z-index: 1000;
				background: #fff;
				border-bottom: 1px solid #ddd;
				box-shadow: 0 2px 4px rgba(0,0,0,0.1);
			}
			</style>
			<script>
			jQuery(document).ready(function($) {
				var path = window.location.pathname;
				var m = path.match(/\/editor\/(\w+)\/\w+\/\w+\/\w+\/\w+\/(\d+)/);
				if (!m) return;
				var cookieKey = "ca_scroll_" + m[1] + "_" + m[2];

				// Restaurer la position au chargement
				var saved = document.cookie.match(new RegExp("(?:^|; )" + cookieKey + "=(\\\\d+)"));
				if (saved) {
					var pos = parseInt(saved[1], 10);
					if (pos > 0) {
						$(window).scrollTop(pos);
					}
					// Supprimer le cookie après utilisation
					document.cookie = cookieKey + "=; path=/; max-age=0";
				}

				// Sauvegarder la position au clic sur Enregistrer
				$(".control-box a[onclick*=submit], .control-box input[type=submit]").on("click", function() {
					var scrollPos = $(window).scrollTop();
					document.cookie = cookieKey + "=" + scrollPos + "; path=/; max-age=120";
				});
			});
			</script>';
        return $pa_params;
    }

    static public function getRoleActionList() {
        return [];
    }
}
