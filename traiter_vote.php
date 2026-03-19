<?php
// 1. Configuration de l'environnement
date_default_timezone_set('Europe/Paris');
header('Content-Type: application/json');

// 2. Connexion à la base de données (Variables Railway)
$serveur = getenv('PGHOST') ?: "127.0.0.1";
$port    = getenv('PGPORT') ?: "5432";
$utilisateur = getenv('PGUSER') ?: "postgres";
$mot_de_passe = getenv('PGPASSWORD') ?: "hockey123";
$nom_base = getenv('PGDATABASE') ?: "Hockey";

try {
    $dsn = "pgsql:host=$serveur;port=$port;dbname=$nom_base";
    $ConnexionBDD = new PDO($dsn, $utilisateur, $mot_de_passe);
    $ConnexionBDD->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 3. Récupération des données envoyées par le JavaScript
    $donnees = json_decode(file_get_contents('php://input'), true);

    if (!$donnees) {
        echo json_encode(['succes' => false, 'message' => 'Données invalides.']);
        exit;
    }

    $id_joueur   = (int)$donnees['id_joueur'];
    $id_match    = (int)$donnees['id_match'];
    $action      = $donnees['action']; 
    $note        = isset($donnees['note']) ? (float)$donnees['note'] : null;
    $commentaire = isset($donnees['commentaire']) ? trim($donnees['commentaire']) : null;
    
    // Récupération de l'IP réelle sur Railway
    $ip_votant = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];

    // 4. Vérification de l'état du match et de l'heure (Sécurité stricte)
    $reqMatch = $ConnexionBDD->prepare("SELECT date_match, statut FROM Matchs WHERE id_match = ?");
    $reqMatch->execute([$id_match]);
    $match = $reqMatch->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        echo json_encode(['succes' => false, 'message' => 'Match introuvable.']);
        exit;
    }

    $maintenant = new DateTime();
    $heure_actuelle = $maintenant->format('H:i');
    $date_actuelle = $maintenant->format('Y-m-d');

    // Vérification de la fenêtre de tir : Uniquement le jour J, entre 10h et 17h, si non clôturé
    $votes_ouverts = false;
    if ($match['date_match'] == $date_actuelle && $match['statut'] != 'Clôturé') {
        if ($heure_actuelle >= "10:00" && $heure_actuelle <= "17:00") {
            $votes_ouverts = true;
        }
    }

    if (!$votes_ouverts) {
        echo json_encode(['succes' => false, 'message' => 'Action impossible : Les votes sont fermés. (Ouverture de 10h à 17h le jour du match)']);
        exit;
    }

    // 5. Traitement des actions
    if ($action === "vote_motm") {
        // Vérifier si l'IP a déjà voté pour le MOTM sur ce match
        $check = $ConnexionBDD->prepare("SELECT id_vote FROM Votes WHERE id_match = ? AND ip_votant = ? AND note IS NULL");
        $check->execute([$id_match, $ip_votant]);

        if ($check->rowCount() > 0) {
            echo json_encode(['succes' => false, 'message' => 'Vous avez déjà voté pour le MOTM de ce match !']);
        } else {
            // Insertion du vote MOTM simple (On ne met pas à jour la table Joueurs ici)
            $ins = $ConnexionBDD->prepare("INSERT INTO Votes (id_match, id_joueur, ip_votant, note) VALUES (?, ?, ?, NULL)");
            $ins->execute([$id_match, $id_joueur, $ip_votant]);
            
            echo json_encode(['succes' => true, 'message' => 'Votre vote pour l\'Homme du Match a été pris en compte !']);
        }
    } 
    
    else if ($action === "noter") {
        // Vérifier si l'IP a déjà noté CE joueur pour CE match
        $checkNote = $ConnexionBDD->prepare("SELECT id_vote FROM Votes WHERE id_match = ? AND ip_votant = ? AND id_joueur = ? AND note IS NOT NULL");
        $checkNote->execute([$id_match, $ip_votant, $id_joueur]);

        if ($checkNote->rowCount() > 0) {
            echo json_encode(['succes' => false, 'message' => 'Vous avez déjà noté ce joueur pour ce match !']);
        } else {
            // Insertion de la note
            $ins = $ConnexionBDD->prepare("INSERT INTO Votes (id_match, id_joueur, ip_votant, note, commentaire) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$id_match, $id_joueur, $ip_votant, $note, $commentaire]);
            
            echo json_encode(['succes' => true, 'message' => 'Note enregistrée ! Merci pour votre retour.']);
        }
    }

} catch (PDOException $e) {
    echo json_encode(['succes' => false, 'message' => 'Erreur technique : ' . $e->getMessage()]);
}
?>