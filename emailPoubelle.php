<?php

//-----------------------------------------------------------
// Title : Email Poubelle
// Licence : GNU GPL v3 : http://www.gnu.org/licenses/gpl.html
// Author : David Mercereau - david [aro] mercereau [.] info
// Home : http://poubelle.zici.fr
// Date : 08/2013
// Version : 1.0
// Depend : Postifx (postmap command) php-pdo
//----------------------------------------------------------- 

// @todo
// 	form ergonomie
// 	sqlite
//	disable time	

//////////////////
// Init & check
//////////////////

define('VERSION', '1.0');

if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 'On');
	echo '<div class="highlight-2">Debug activé <br />'.print_r($_REQUEST).'</div>';
}

if (defined(DOMAIN)) {
	exit('<div class="highlight-1">Erreur : Problème de configuration</div>');
}
// check writable work directory
if (!is_writable(DATA)) {
	exit('<div class="highlight-1">Erreur : le répertoire de travail ne peut pas être écrit. Merci de contacter l\'administrateur</div>');
}
// check alias file is_writable 
if (!is_writable(FICHIERALIAS)) {
	exit('<div class="highlight-1">Erreur : le fichier d\'alias ne peut pas être écrit. Merci de contacter l\'administrateur</div>');
}
// check blacklist file is_writable
if (defined('BLACKLIST') && !is_readable(BLACKLIST)) {
    exit('<div class="highlight-1">Erreur : un fichier de blacklist est renseigné mais n\'est pas lisible. Merci de contacter l\'administrateur</div>');
}
// check aliasdeny file is_writable
if (defined('ALIASDENY') && !is_readable(ALIASDENY)) {
    exit('<div class="highlight-1">Erreur : un fichier d\'alias interdit est renseigné mais n\'est pas lisible. Merci de contacter l\'administrateur</div>');
}

// Connect DB
try {
	if (preg_match('/^sqlite/', DB)) {
		$dbco = new PDO(DB);
	} else {
		$dbco = new PDO(DB, DBUSER, DBPASS);
	}
	$dbco->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch ( PDOException $e ) {
	die('Connexion à la base '.$e->getMessage());
}
// Create DB if not exists
try {
// status : 0=not verified - 3=disable - 5=active
$create = $dbco->query("CREATE TABLE IF NOT EXISTS ".DBTABLEPREFIX."alias (
						id INTEGER PRIMARY KEY  AUTO_INCREMENT,
						status INTEGER(1) NOT NULL,
						alias CHAR(150) NOT NULL UNIQUE,
						email CHAR(150) NOT NULL,
						dateCreat DATETIME NOT NULL,
						dateExpir DATETIME,
						comment TEXT);");
} catch ( PDOException $e ) {
	echo '<div class="highlight-1">Erreur à l\'initialisation des tables. Merci de contacter l\'administrateur ';
	if (DEBUG) { $e->getMessage(); }
	echo '</div>';
	die();
}

//////////////////
// Start program
//////////////////

// get process act
$action = isset($_GET['act']) ? $_GET['act'] : '';
switch ($action) {
	case "validemail" :
		$get_value = urlUnGen($_GET['value']);
		echo $dbco->query("SELECT COUNT(*) FROM ".DBTABLEPREFIX."alias WHERE id = '".$get_value['id']."' AND status = 0")->fetchColumn();
		if ($dbco->query("SELECT COUNT(*) FROM ".DBTABLEPREFIX."alias WHERE id = '".$get_value['id']."' AND status = 0")->fetchColumn() != 0) {
			UpdateStatusAlias($get_value['id'], $get_value['alias_full'], 5);
			echo '<div class="highlight-3">Votre email poubelle <b>'.$get_value['alias_full'].'</b> est maintenant actif</div>';
		} else {
			echo '<div class="highlight-1">Erreur : ID introuvable ou déjà validé</div>';
		}
	break;
	case "disable" :
		$get_value = urlUnGen($_GET['value']);
		DisableAlias($get_value['id'], $get_value['alias_full'], null);
	break;
	case "enable" :
		$get_value = urlUnGen($_GET['value']);
		EnableAlias($get_value['id'], $get_value['alias_full'], null);
	break;
	case "delete" :
		$get_value = urlUnGen($_GET['value']);
		DeleteAlias($get_value['id'], $get_value['alias_full']);
	break;
}
// Form
if (isset($_POST['list'])) {
	$email=strtolower($_POST['email']);
	if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
		echo '<div class="highlight-1">Erreur : Adresse email incorrect</div>';
	} else if (! VerifMXemail($email)) {
		echo '<div class="highlight-1">Erreur : Adresse email incorrect (2)</div>';
	} else if (ListeAlias($email)) {
		echo '<div class="highlight-3">Un email vient de vous être envoyé</div>';
	} else {
		echo '<div class="highlight-1">Erreur : aucun email actif connu</div>';
	}
} else if (isset($_POST['email']) && isset($_POST['alias'])) {
	$alias=strtolower($_POST['alias']);
	$email=strtolower($_POST['email']);
	$domain=$_POST['domain'];
	$life=$_POST['life'];
	$comment=$_POST['comment'];
	$alias_full=$alias.'@'.$domain;
	// Check form
	if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
		echo '<div class="highlight-1">Erreur : Adresse email incorrect</div>';
	} else if (! VerifMXemail($email)) {
		echo '<div class="highlight-1">Erreur : Adresse email incorrect (2)</div>';
	} else if (! preg_match('#^[\w.-]+$#',$alias)) {
		echo '<div class="highlight-1">Erreur : Format de l\'email poubelle incorrect</div>';
	} else if (! preg_match('#'.$domain.'#',DOMAIN)) {
		echo '<div class="highlight-1">Erreur : ce domain n\'est pas pris en charge</div>';
	} else if (AliasDeny($alias)) {
		echo '<div class="highlight-1">Erreur : email poubelle interdit</div>';
	} else if (BlacklistEmail($email)) {
		echo '<div class="highlight-1">Erreur : vous avez été blacklisté sur ce service</div>';
	// add 
	} elseif (isset($_POST['add'])) {
		if ($dbco->query("SELECT COUNT(*) FROM ".DBTABLEPREFIX."alias WHERE alias = '".$alias_full."'")->fetchColumn() != 0) {
			echo '<div class="highlight-1">Erreur : cet email poubelle est déjà utilisé</div>';
		} else {
			if ($dbco->query("SELECT COUNT(*) FROM ".DBTABLEPREFIX."alias WHERE email = '".$email."' AND status > 0")->fetchColumn() != 0) {
				AjouterAlias(5, $alias_full, $email, $life, $comment);
				echo '<div class="highlight-3">Votre email poubelle <b>'.$alias_full.' > '.$email.'</b> est maintenant actif</div>';
			} else {
				$lastId=AjouterAlias(0, $alias_full, $email, $life, $comment);
				$message= "Confirmation de la création de votre redirection email poubelle : ";
				$message= $alias_full.' => '.$email."\n";
				$message= "Cliquer sur le lien ci-dessous pour confirmer : \n";
				$message.= "\t * ".urlGen('validemail',$lastId,$alias_full)."\n";
				$message.= "Pour supprimer cet email poubelle vous pouvez vous rendre sur le lien ci-dessou : \n";
				$message.= "\t * ".urlGen('del',$lastId,$alias_full)."\n";
				$message.= "\n";
				$message.= "Après confirmation, vous pourez suspendre temporairement cet email poubelle vous pouvez vous rendre sur le lien ci-dessou : \n";
				$message.= "\t * ".urlGen('disable',$lastId,$alias_full)."\n";
				SendEmail($email,'Confirmation alias '.$alias,$message);
				echo '<div class="highlight-2">Votre email ('.$email.') nous étant inconnu, une confirmation vous a été envoyé par email.</div>';
			}
		}
	// delete
	} else if (isset($_POST['del'])) {
		if ($id = $dbco->query("SELECT id FROM ".DBTABLEPREFIX."alias WHERE email = '".$email."' AND alias = '".$alias_full."'")->fetchColumn()) {
			$message= "Confirmation de la création de votre redirection email poubelle : ";
			$message= $alias_full.' => '.$email."\n";
			$message= "Cliquer sur le lien ci-dessous pour confirmer la suppression : \n";
			$message.= "\t * ".urlGen('del',$id,$alias_full)."\n\n";
			$message.= "Sinon pour suspendre temporairement cet email poubelle vous pouvez vous rendre sur le lien ci-dessou : \n";
			$message.= "\t * ".urlGen('disable',$id,$alias_full)."\n";
			SendEmail($email,'Suppression de l\'alias '.$alias,$message);
			echo '<div class="highlight-2">Un email de confirmation vient de vous être envoyé.</div>';
		} else {
			echo '<div class="highlight-1">Erreur : impossible de trouver cet email poubelle</div>';
		}
	// disable
	} else if (isset($_POST['disable'])) {
		DisableAlias(null, $alias_full, $email);
	// enable
	} else if (isset($_POST['enable'])) {
		EnableAlias(null, $alias_full, $email);
	}

	// memory email
	if (isset($_POST['memory'])) {
		setcookie ("email", $email, time() + 31536000);
	} else if (isset($_COOKIE['email'])) {
		unset($_COOKIE['email']);
	}
}
// Close connexion DB
$dbco = null;

//////////////////
// Printing form
//////////////////

?>

<form action="<?= URLPAGE?>" method="post">
<div id="form-email">
	<label for="email">Votre email réel : </label>
	<input type="text" name="email" <?php if (isset($_COOKIE['email'])) { echo 'value="'.$_COOKIE['email'].'"'; } ?> id="input-email" size="24" border="0"  onkeyup="printForm()" onchange="printForm()"  /> 
	<input class="button2" type="submit" name="list" id="button-list" value="Lister" />
	<input type="checkbox" name="memory" id="check-memory" <?php if (isset($_COOKIE['email'])) { echo 'checked="checked" '; } ?>/> Mémoriser
</div>
<div id="form-alias">
	<label for="alias">Nom de l'email poubelle : </label>
	<input type="text" name="alias" id="input-alias" size="24" border="0" onkeyup="printForm()" onchange="printForm()" placeholder="Ex : jean-petiteannonce" /> @<?php
		$domains = explode(';', DOMAIN);
		if (count($domains) == 1) {
			echo DOMAIN.'<input type="hidden" value="'.DOMAIN.'" name="domain" id="input-domain" />';
		} else {
			echo '<select name="domain" id="input-domain">';
			foreach ($domains as $one_domain)  {
				echo '<option value="'.$one_domain.'">'.$one_domain.'</option>';
			}
			echo '</select>';
		}
	?>
	<select name="life" id="input-life">
		<option value="0">Illimité</option>
		<option value="7200">2 heure</option>
		<option value="21600">6 heures</option>
		<option value="86400">1 jour</option>
		<option value="604800">7 jours</option>
		<option value="1296000">15 jours</option>
		<option value="2592000">30 jours</option>
		<option value="7776000">90 jours</option>
	</select>
</div>
<div id="form-comment">
	<label for="comment">Un commentaire pour l'ajout ? (pour votre mémoire)</label>
	<input type="text" name="comment" size="54" placeholder="Ex : Inscription sur zici.fr" />
</div>
<div id="form-submit">
	<input class="button" type="submit" id="button-add" name="add" value="Activer" /> -
	<input class="button" type="submit" id="button-disable" name="disable" value="Susprendre" /> -
	<input class="button" type="submit" id="button-enable" name="enable" value="Reprendre" /> -
	<input class="button" type="submit" id="button-del" name="del" value="Supprimer" />
</div>
</form>

<script type="text/javascript">
	function validateEmail(email) { 
		var re = /^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
		return re.test(email);
	} 
	function printForm() {
		console.log("appel de la fonction : " + document.getElementById('input-email').value + document.getElementById('input-alias').value);
		if (validateEmail(document.getElementById('input-email').value) && document.getElementById('input-alias').value != '') {
			console.log("Les 2 sont OK");
			document.getElementById('input-alias').disabled = false; 
			document.getElementById('input-domain').disabled = false; 
			document.getElementById('button-list').disabled = false; 
			document.getElementById('button-add').disabled = false; 
			document.getElementById('button-disable').disabled = false; 
			document.getElementById('button-del').disabled = false; 
			document.getElementById('input-life').disabled = false; 
			document.getElementById('form-comment').style.display = "block" ;
		} else if (validateEmail(document.getElementById('input-email').value)) {
			console.log("email ok");
			document.getElementById('input-alias').disabled = false; 
			document.getElementById('input-domain').disabled = false; 
			document.getElementById('button-list').disabled = false;
			document.getElementById('input-life').disabled = false;
			document.getElementById('form-comment').style.display = "display" ;
			document.getElementById('button-add').disabled = true; 
			document.getElementById('button-disable').disabled = true; 
			document.getElementById('button-del').disabled = true; 
			document.getElementById('input-life').disabled = true;
			document.getElementById('form-comment').style.display = "none" ;
		} else {
			console.log("rien OK");
			document.getElementById('input-alias').disabled = true; 
			document.getElementById('input-domain').disabled = true; 
			document.getElementById('button-list').disabled = true; 
			document.getElementById('button-add').disabled = true; 
			document.getElementById('button-disable').disabled = true; 
			document.getElementById('button-del').disabled = true; 
			document.getElementById('input-life').disabled = true;
			document.getElementById('form-comment').style.display = "none" ;
		}
	}
	printForm();
</script>
<p>Version <?= VERSION ?> - Créé par David Mercereau sous licence GNU GPL v3</p>
<p>Télécharger et utiliser ce script sur le site du projet <a target="_blank" href="http://forge.zici.fr/p/emailpoubelle-php/">emailPoubelle.php</a></p>

<?php echo '<p>Upgrade note : '.CheckUpdate().'</p>'; ?>
