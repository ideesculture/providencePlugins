#!/bin/bash
# Purge les sorties générées des plugins INRAP (etatsInrap, exportInrap, bookCreator).
#
# Ces répertoires tmp/ accumulent les documents produits à la demande : lettres de versement,
# bordereaux, PDF d'export. Le code des plugins ne les nettoie pas. Relevé le 09/09/2026 :
# 189 Mo dans etatsInrap/tmp, dont un seul courrier pesait 122 Mo. Tout y est régénéré à chaque
# demande : rien n'est perdu.
#
# GARDE-FOU, appris deux fois à la dure le 09/09/2026 :
#   1. un `find -type f -mtime +2 -delete` naïf a supprimé `etatsInrap/tmp/.gitkeep` et
#      `.htaccess`, suivis par git. Le .htaccess est vital : le .htaccess racine de Providence
#      refuse toute extension hors liste blanche, et c'est lui qui réautorise docx/odt/pdf/xlsx.
#      Sans lui, plus aucune lettre ne se télécharge.
#   2. filtrer par extension ne suffisait pas : `exportInrap/tmp` contient QUATRE fichiers
#      générés (pdf-content_*.html, temp_*.pdf) committés par erreur à l'import des plugins
#      (70dc4ca) — donc suivis, donc à ne pas toucher.
#
# Règle retenue : on ne supprime QUE ce que git ignore. Si git ne peut pas répondre, on
# n'efface RIEN — un répertoire qui grossit se rattrape, un fichier versionné détruit non.

set -u
RACINE="/var/www/comodo/collectiveaccess/providencePlugins"
JOURS=2
REPS=(etatsInrap/tmp exportInrap/tmp bookCreator/tmp)

horo() { date '+%Y-%m-%d %H:%M:%S'; }

# Le dépôt appartient à gautier ; ce script tourne sous www-data -> safe.directory obligatoire.
GIT=(git -c "safe.directory=$RACINE" -C "$RACINE")

if ! "${GIT[@]}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "$(horo) ARRÊT : git ne répond pas sur $RACINE — aucune suppression."
    exit 1
fi

total_n=0; total_o=0
for r in "${REPS[@]}"; do
    d="$RACINE/$r"
    [ -d "$d" ] || continue

    # Liste des fichiers SUIVIS sous ce répertoire : intouchables, quelle que soit leur extension.
    declare -A SUIVIS=()
    while IFS= read -r -d '' f; do SUIVIS["$RACINE/$f"]=1; done \
        < <("${GIT[@]}" ls-files -z -- "$r" 2>/dev/null)

    n=0; o=0
    while IFS= read -r -d '' f; do
        [ -n "${SUIVIS[$f]:-}" ] && continue          # suivi par git : on passe
        case "$(basename "$f")" in .*) continue;; esac # ceinture et bretelles sur les fichiers cachés
        t=$(stat -c%s "$f" 2>/dev/null) || continue
        rm -f -- "$f" && { n=$((n+1)); o=$((o+t)); }
    done < <(find "$d" -type f -mtime +$JOURS -print0 2>/dev/null)

    unset SUIVIS
    if [ "$n" -gt 0 ]; then
        # sous-répertoires devenus vides (un courrier = un dossier par idno) ; jamais la racine tmp/
        find "$d" -mindepth 1 -type d -empty -delete 2>/dev/null
        echo "$(horo) $r : $n fichier(s), $((o/1048576)) Mo supprimé(s)"
        total_n=$((total_n+n)); total_o=$((total_o+o))
    fi
done

[ "$total_n" -gt 0 ] && echo "$(horo) total : $total_n fichier(s), $((total_o/1048576)) Mo"
exit 0
