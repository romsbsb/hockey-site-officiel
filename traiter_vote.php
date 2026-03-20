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
    
    // NOUVEAU : On lit le ticket (cookie) du téléphone pour différencier les utilisateurs sur le même Wi-Fi
    // (Si par hasard le navigateur bloque les cookies, on utilise l'IP en roue de secours)
    $ip_votant = $_COOKIE['hscsm_voter_id'] ?? $_SERVER['REMOTE_ADDR'];

    // Vérification de la fenêtre de vote (10h - 17h le jour J, match non clôturé)
    // Ajout de "heure_match" dans la requête
    $reqMatch = $ConnexionBDD->prepare("SELECT date_match, heure_match, statut FROM Matchs WHERE id_match = ?");
    $reqMatch->execute([$id_match]);
    $match = $reqMatch->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        echo json_encode(['succes' => false, 'message' => 'Match introuvable.']);
        exit;
    }

    $maintenant = new DateTime();
    $heure_actuelle = $maintenant->format('H:i');
    $date_actuelle = $maintenant->format('Y-m-d');

    $votes_ouverts = false;
    // 5. Statut des votes (Ouverts pendant 4h à partir du coup d'envoi)
    $votes_ouverts = false;
    
    // On combine la date et l'heure du match en un seul objet
    // Vérification de la fenêtre de vote (4h chrono à partir du match)
    $debutMatch = new DateTime($match['date_match'] . ' ' . $match['heure_match']);
    
    $finVote = clone $debutMatch;
    $finVote->modify('+4 hours');

    $maintenant = new DateTime();
    $votes_ouverts = false;

    // Si on est dans le bon créneau horaire
    if ($maintenant >= $debutMatch && $maintenant <= $finVote) {
        $votes_ouverts = true;
    }

    if (!$votes_ouverts) {
        echo json_encode(['succes' => false, 'message' => 'Les votes sont clos (délai de 4h dépassé) ou pas encore ouverts.']);
        exit;
    }

    // --- LOGIQUE DE VOTE ET DE MODIFICATION DES VOTES ---

    // On accepte les deux actions puisqu'elles font la même chose : vérifier et insérer ou mettre à jour
    if ($action === "vote_motm" || $action === "modifier_motm") {
        // 1. On cherche dans la table spécifique votes_motm
        $check = $ConnexionBDD->prepare("SELECT id FROM votes_motm WHERE id_match = ? AND ip_votant = ?");
        $check->execute([$id_match, $ip_votant]);
        $voteExistant = $check->fetch(PDO::FETCH_ASSOC);

        if ($voteExistant) {
            // L'utilisateur a déjà voté : on MET À JOUR avec le nouveau joueur
            $update = $ConnexionBDD->prepare("UPDATE votes_motm SET id_joueur = ? WHERE id = ?");
            $update->execute([$id_joueur, $voteExistant['id']]);
            
            echo json_encode(['succes' => true, 'message' => 'Ton vote a été modifié avec succès !']);
        } else {
            // Aucun vote trouvé : on CRÉE un nouveau vote
            $ins = $ConnexionBDD->prepare("INSERT INTO votes_motm (id_match, id_joueur, ip_votant) VALUES (?, ?, ?)");
            $ins->execute([$id_match, $id_joueur, $ip_votant]);
            
            echo json_encode(['succes' => true, 'message' => 'Vote MOTM enregistré !']);
        }
        if ($voteExistant) {
            // L'utilisateur a déjà voté : on MET À JOUR son vote avec le nouveau joueur
            $update = $ConnexionBDD->prepare("UPDATE Votes SET id_joueur = ? WHERE id_vote = ?");
            $update->execute([$id_joueur, $voteExistant['id_vote']]);
            
            echo json_encode(['succes' => true, 'message' => 'Ton vote a été modifié avec succès !']);
        } else {
            // Aucun vote trouvé : on CRÉE un nouveau vote
            $ins = $ConnexionBDD->prepare("INSERT INTO Votes (id_match, id_joueur, ip_votant, note) VALUES (?, ?, ?, NULL)");
            $ins->execute([$id_match, $id_joueur, $ip_votant]);
            
            echo json_encode(['succes' => true, 'message' => 'Vote MOTM enregistré !']);
        }
    } 
    
    else if ($action === "noter") {
        // 1. On cherche si l'utilisateur a déjà noté CE joueur précis pour CE match
        $checkNote = $ConnexionBDD->prepare("SELECT id_vote FROM Votes WHERE id_match = ? AND ip_votant = ? AND id_joueur = ? AND note IS NOT NULL");
        $checkNote->execute([$id_match, $ip_votant, $id_joueur]);
        $noteExistante = $checkNote->fetch(PDO::FETCH_ASSOC);

        if ($noteExistante) {
            // L'utilisateur a déjà noté ce joueur : on MET À JOUR la note et le commentaire
            $updateNote = $ConnexionBDD->prepare("UPDATE Votes SET note = ?, commentaire = ? WHERE id_vote = ?");
            $updateNote->execute([$note, $commentaire, $noteExistante['id_vote']]);
            
            echo json_encode(['succes' => true, 'message' => 'Ta note pour ce joueur a été mise à jour !']);
        } else {
            // Aucune note trouvée : on CRÉE une nouvelle note
            $ins = $ConnexionBDD->prepare("INSERT INTO Votes (id_match, id_joueur, ip_votant, note, commentaire) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$id_match, $id_joueur, $ip_votant, $note, $commentaire]);
            
            echo json_encode(['succes' => true, 'message' => 'Note enregistrée !']);
        }
    }

} catch (PDOException $e) {
    echo json_encode(['succes' => false, 'message' => 'Erreur technique.']);
}
?>