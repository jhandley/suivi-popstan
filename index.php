<?php
header('Content-Type: text/html; charset=utf-8');
?>
<html>
<head>
<title>RGPH Popstan Suivi</title>
<style>
table, td, th {
    border: 1px solid black;
}
table {
    border-collapse: collapse;
    width: 100%;
}
</style>
</head>
<body>
<?php

// Envoyer une requete au serveur sync du CSPro pour avoir les cases.
// Le resultat sera une liste des cas dans un array PHP
// { [0]=> 
//        { ["data"]=> 
//					{ [0]=> "101010880022074020101112711010120611 610131" 
//					  [1]=> "10101088002207402010222241101012002 9201010" 
//					  [2]=> "10101088002207402010331085101011029" 
//					  [3]=> "20101088002207402011135011000665250003" 
//					} 
//			["caseids"]=> "010108800220740201" 
//			["deleted"]=> false
//		  } 
//   [1]=> { ["data"]=> 
//					{ [0]=> "101010180031007006101124721010121311 41652104040" 
//					  [1]=> "10101018003100700610231225101011131 92" 
// ...
function recupererCasesDuServeurCSPro($heureDerniereMiseAJour) {
	//  Initialisation de curl
	$curlHandle = curl_init();

	// Definir l'url
	$url = 'http://localhost/CSPro-REST-API/web/dictionaries/CEN2000/cases';
	if ($heureDerniereMiseAJour) {
		$url .= '?updatedSince='.$heureDerniereMiseAJour->format(DateTime::RFC3339);
	}
	curl_setopt($curlHandle, CURLOPT_URL, $url);
	
	// Retour de la reponse (ne pas l'imprimir)
	curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);

	// Lancer la requete
	$reponse = curl_exec($curlHandle);

	// Fermer curl
	curl_close($curlHandle);

	// Convertir du JSON au PHP array
	return json_decode($reponse, true);		
}

// Convertir l'enregistrement du format text du CSPro a un array associative
// avec les identifiants plus les variables cles extraits du text.
// Un enregistrement au format CSPro sera par exemple "101010880022074020101112711010120611  610131"
// ou le premier chiffre (1) indique le type d'enregistrement (person), les deux chiffres qui suit (01)
// represent le province,... Cette fonction produit un array comme:
// 		{ ["TYPE"]=> "1" ["PROVINCE"]=> "01" ["DISTRICT"]=> "01" ["VILLAGE"]=> "088"...
function parseRecord($record) {
	$result = array();
	$recordType = $record[0];
	$result['TYPE'] = $recordType;
	$result['PROVINCE'] = substr($record, 1, 2);
	$result['DISTRICT'] = substr($record, 3, 2);
	$result['VILLAGE'] = substr($record, 5, 3);
	$result['EA'] = substr($record, 8, 3);
	$result['UR'] = substr($record, 11, 1);
	$result['BUILDING'] = substr($record, 12, 3);
	$result['HU'] = substr($record, 15, 3);
	$result['HH'] = substr($record, 18, 1);
	
	if ($recordType == '1') {
		// person
		$sexe = substr($record, 22, 1);
		if ($sexe == '1' || $sexe == '2' || $sexe == ' ') {
			if ($sexe == ' ')
				$sexe = NULL;
			$result['SEX'] = $sexe;
		} else {
			die("Sexe '{$sexe}' inconnu");
		}
		$age = substr($record, 23, 2);
		if (is_numeric($age) || $age == '  ') {
			if ($age == ' ')
				$age = NULL;
			$result['AGE'] = $age;
		} else {
			die("Age '{$age}' inconnu");
		}
	} elseif ($recordType == '2') {
		// housing
	} else {
		die("Record type '{$recordType}' inconnu");
	}
	return $result;
}

// Inserer les menages et les individus dans la base de donnees locale
function insererCasesDansBaseDeDonnees($cases, $conn) {
	
	foreach ($cases as &$case) {
		$records = array_map('parseRecord', $case['data']);

		// Les identifiants seront de meme pour chaque enregistrement dans le cas
		// alors on peut les prendre chez le premier enregistrement.
		$firstRecord = $records[0];
		
		// Voir si ce menage existe deja dans la base et trouver son id
		$stmt = $conn->prepare('SELECT ID FROM menages WHERE PROVINCE=:prov AND DISTRICT=:dist AND VILLAGE=:vill AND EA=:ea AND UR=:ur AND BUILDING=:build AND HU=:hu AND HH=:hh');
		$stmt->bindParam(':prov', $firstRecord['PROVINCE']); 
		$stmt->bindParam(':dist', $firstRecord['DISTRICT']); 
		$stmt->bindParam(':vill', $firstRecord['VILLAGE']); 
		$stmt->bindParam(':ea', $firstRecord['EA']); 
		$stmt->bindParam(':ur', $firstRecord['UR']); 
		$stmt->bindParam(':build', $firstRecord['BUILDING']); 
		$stmt->bindParam(':hu', $firstRecord['HU']); 
		$stmt->bindParam(':hh', $firstRecord['HH']);
		$stmt->execute(); 
		$idMenage = $stmt->fetchColumn();
		if (!$idMenage){
			// Nouveau menage, inserer le menage dans la base de donnees
			$stmt = $conn->prepare('INSERT INTO menages (PROVINCE,DISTRICT,VILLAGE,EA,UR,BUILDING,HU,HH) VALUES(:prov, :dist, :vill, :ea, :ur, :build, :hu, :hh)');
			$stmt->bindParam(':prov', $firstRecord['PROVINCE']); 
			$stmt->bindParam(':dist', $firstRecord['DISTRICT']); 
			$stmt->bindParam(':vill', $firstRecord['VILLAGE']); 
			$stmt->bindParam(':ea', $firstRecord['EA']); 
			$stmt->bindParam(':ur', $firstRecord['UR']); 
			$stmt->bindParam(':build', $firstRecord['BUILDING']); 
			$stmt->bindParam(':hu', $firstRecord['HU']); 
			$stmt->bindParam(':hh', $firstRecord['HH']);
			$stmt->execute();
			$idMenage = $conn->lastInsertId();
		}
		
		// Supprimer les individus de ce menage pour les remplacer apres
		$stmt = $conn->prepare('DELETE FROM individus WHERE ID_MENAGE=:id_men');
		$stmt->bindParam(':id_men', $idMenage); 
		$stmt->execute();
		
		// Inserer les individus dans la base de donnes
		foreach ($records as &$record) {
			if ($record['TYPE'] == 1) {
				// person
				$stmt = $conn->prepare('INSERT INTO individus (ID_MENAGE,AGE,SEXE) VALUES(:id_men, :age, :sexe)');
				$stmt->bindParam(':id_men', $idMenage); 
				$stmt->bindParam(':age', $record['AGE']); 
				$stmt->bindParam(':sexe', $record['SEX']); 
				$stmt->execute();		
			} else {
				// housing - rien a faire comme on l'a deja inserer
			}
		}
	}	
}

function insererMiseAJourHistorique($heureMiseAJour, $conn) {
	$stmt = $conn->prepare("INSERT INTO `mise-a-jour-historique` (DATE_HEURE) VALUES( ? )");
	$heureFormatBaseDonnees = $heureMiseAJour->format('Y-m-d H:i:s');
	$stmt->bindParam(1, $heureFormatBaseDonnees);
	$stmt->execute();
}

function derniereMiseAJour($conn) {
	$dateStr = $conn->query('SELECT MAX(DATE_HEURE) FROM `mise-a-jour-historique`')->fetchColumn();
	if (!$dateStr)
		return NULL;
	else 
		return DateTime::createFromFormat('Y-m-d H:i:s', $dateStr, new \DateTimeZone("UTC"));
}

function mettreAJour($conn) {	
	$heureDerniereMiseAJour = derniereMiseAJour($conn);
	$heureActuelle = new \DateTime(null, new \DateTimeZone("UTC"));
	
	// Chercher les cas qui ont ete modifie depuis la derniere mise a jour
	$cases = recupererCasesDuServeurCSPro($heureDerniereMiseAJour);
	
	// Actualiser la base de donnees locale avec les cas recus
	insererCasesDansBaseDeDonnees($cases, $conn);
	
	// Mettre a jour l'historique
	insererMiseAJourHistorique($heureActuelle, $conn);
	
	$noCases = count($cases);
	echo "<p>{$noCases} ménages mis à jour.</p>";
}

function tableauRecapulatif($conn) {
	$noMenages = $conn->query('SELECT COUNT(*) FROM menages')->fetchColumn();
	$noHommes = $conn->query('SELECT COUNT(*) FROM individus WHERE SEXE=1')->fetchColumn();
	$noFemmes = $conn->query('SELECT COUNT(*) FROM individus WHERE SEXE=2')->fetchColumn();
	$popTotal = $noHommes + $noFemmes;
	echo "<table>";
	echo "<tr><th>Ménages</th><th>Population</th><th>Hommes</th><th>Femmes</th></tr>";
	echo "<tr><th>{$noMenages}</th><th>{$popTotal}</th><th>{$noHommes}</th><th>{$noFemmes}</th></tr>";
	echo "</table>";	
}

$servername = "localhost";
$dbname = "suivi";
$username = "suiviwebapp";
$password = "MLN5MF6UDMQNxPRD";
$conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
	mettreAJour($conn);
}

tableauRecapulatif($conn);
?>

<br/>
<form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
   <input type="submit" name="submit" value="Mettre a Jour"> 
</form>

</body>
</html>