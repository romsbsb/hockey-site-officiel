<?php
date_default_timezone_set('Europe/Paris');
header('Content-Type: application/json');

$serveur = getenv('PGHOST') ?: "127.0.0.1";
$port    = getenv('PGPORT') ?: "5432";
$utilisateur = getenv('PGUSER') ?: "postgres";
$mot_de_passe = getenv('PGPASSWORD') ?: "hockey123";
$nom_base = getenv('PGDATABASE') ?: "Hockey";

try {
    $dsn = "pgsql:host=$serveur;port=$port;dbname=$nom_base";
    $ConnexionBDD = new PDO($dsn, $utilisateur, $mot_de_passe);
    $ConnexionBDD->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
    
    // Lecture du ticket (cookie) ou IP en roue de secours
    $ip_votant = $_COOKIE['hscsm_voter_id'] ?? $_SERVER['REMOTE_ADDR'];

    // Vérification de l'existence du match
    $reqMatch = $ConnexionBDD->prepare("SELECT date_match, heure_match, statut FROM Matchs WHERE id_match = ?");
    $reqMatch->execute([$id_match]);
    $match = $reqMatch->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        echo json_encode(['succes' => false, 'message' => 'Match introuvable.']);
        exit;
    }

    // Vérification de la fenêtre de vote (4h chrono à partir du match)
    $debutMatch = new DateTime($match['date_match'] . ' ' . $match['heure_match']);
    $finVote = clone $debutMatch;
    $finVote->modify('+4 hours');
    $maintenant = new DateTime();
$votes_ouverts = true;

    // --- LOGIQUE DE VOTE ET DE MODIFICATION DES VOTES ---

    if ($action === "vote_motm" || $action === "modifier_motm") {
        
        // 1. On gère UNIQUEMENT la table votes_motm
        $check = $ConnexionBDD->prepare("SELECT id FROM votes_motm WHERE id_match = ? AND ip_votant = ?");
        $check->execute([$id_match, $ip_votant]);
        $voteExistant = $check->fetch(PDO::FETCH_ASSOC);

        if ($voteExistant) {
            // L'utilisateur a déjà voté : on MET À JOUR
            $update = $ConnexionBDD->prepare("UPDATE votes_motm SET id_joueur = ? WHERE id = ?");
            $update->execute([$id_joueur, $voteExistant['id']]);
            
            echo json_encode(['succes' => true, 'message' => 'Ton vote a été modifié avec succès !']);
        } else {
            // Aucun vote trouvé : on CRÉE
            $ins = $ConnexionBDD->prepare("INSERT INTO votes_motm (id_match, id_joueur, ip_votant) VALUES (?, ?, ?)");
            $ins->execute([$id_match, $id_joueur, $ip_votant]);
            
            echo json_encode(['succes' => true, 'message' => 'Vote MOTM enregistré !']);
        }
        exit; // On stoppe l'exécution ici pour garantir un JSON propre
    } 
    
    else if ($action === "noter") {
        
        // 2. On gère UNIQUEMENT la table Votes
        $checkNote = $ConnexionBDD->prepare("SELECT id_vote FROM Votes WHERE id_match = ? AND ip_votant = ? AND id_joueur = ? AND note IS NOT NULL");
        $checkNote->execute([$id_match, $ip_votant, $id_joueur]);
        $noteExistante = $checkNote->fetch(PDO::FETCH_ASSOC);

        if ($noteExistante) {
            // Mise à jour de la note
            $updateNote = $ConnexionBDD->prepare("UPDATE Votes SET note = ?, commentaire = ? WHERE id_vote = ?");
            $updateNote->execute([$note, $commentaire, $noteExistante['id_vote']]);
            
            echo json_encode(['succes' => true, 'message' => 'Ta note pour ce joueur a été mise à jour !']);
        } else {
            // Création de la note
            $ins = $ConnexionBDD->prepare("INSERT INTO Votes (id_match, id_joueur, ip_votant, note, commentaire) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$id_match, $id_joueur, $ip_votant, $note, $commentaire]);
            
            echo json_encode(['succes' => true, 'message' => 'Note enregistrée !']);
        }
        exit;
    } else {
        echo json_encode(['succes' => false, 'message' => 'Action non reconnue.']);
        exit;
    }

} catch (PDOException $e) {
    echo json_encode(['succes' => false, 'message' => 'Erreur technique.']);
}
?>