<?php
/* =========================================================================
   CompoZ'IT - Export de la base (page fusionnee)
   -------------------------------------------------------------------------
   L'export SQL et l'export CSV sont desormais regroupes avec l'import dans
   la page unique import_database.php. Cette page ne subsiste que pour
   rediriger les anciens liens et favoris, en conservant la requete
   (?download=1 ou ?download=csv).
   ========================================================================= */

$query = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
header('Location: import_database.php' . ($query !== '' ? '?' . $query : ''));
exit();
