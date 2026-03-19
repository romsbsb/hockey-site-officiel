<?php
header('Content-Type: application/json');

// --- RÉCUPÉRATION DES DONNÉES JSON ---
$donnees = json_decode(file_get_contents('php://input'), true);

if (!$donnees) {
    echo json_encode(['succes' => false, 'message' => 'Aucune donnée reçue.']);
    exit;
}

$id_joueur   = (int)$donnees['id_joueur'];
$id_match    = (int)$donnees['id_match'];
$action      = $donnees['action']; 
$note        = isset($donnees['note']) ? (float)$donnees['note'] : null;
$commentaire = isset($donnees['commentaire']) ? trim($donnees['commentaire']) : null;
$ip_votant   = $_SERVER['REMOTE_ADDR'];

// --- CONFIGURATION CONNEXION RAILWAY ---
$serveur      = getenv('PGHOST') ?: "127.0.0.1";
$port         = getenv('PGPORT') ?: "5432";
$utilisateur  = getenv('PGUSER') ?: "postgres";
$mot_de_passe = getenv('PGPASSWORD') ?: "hockey123";
$nom_base     = getenv('PGDATABASE') ?: "Hockey";

try {
    $dsn = "pgsql:host=$serveur;port=$port;dbname=$nom_base";
    $ConnexionBDD = new PDO($dsn, $utilisateur, $mot_de_passe);
    $ConnexionBDD->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Vérifier si le match n'est pas clôturé
    $reqStatut = $ConnexionBDD->prepare("SELECT statut FROM Matchs WHERE id_match = ?");
    $reqStatut->execute([$id_match]);
    $match = $reqStatut->fetch(PDO::FETCH_ASSOC);

    if ($match && $match['statut'] === 'Clôturé') {
        echo json_encode(['succes' => false, 'message' => 'Action impossible : Ce match est clôturé.']);
        exit;
    }

    // 2. Logique selon l'action (VOTE MOTM ou NOTER)
    if ($action === "vote_motm") {
        // Vérifier si l'IP a déjà voté pour le MOTM sur ce match (note est NULL pour les votes MOTM simples)
        $check = $ConnexionBDD->prepare("SELECT id_vote FROM Votes WHERE id_match = ? AND ip_votant = ? AND note IS NULL");
        $check->execute([$id_match, $ip_votant]);

        if ($check->rowCount() > 0) {
            echo json_encode(['succes' => false, 'message' => 'Tu as déjà voté pour l\'Homme du Match !']);
        } else {
            $ins = $ConnexionBDD->prepare("INSERT INTO Votes (id_match, id_joueur, ip_votant, note) VALUES (?, ?, ?, NULL)");
            $ins->execute([$id_match, $id_joueur, $ip_votant]);
            
            // Bonus : On peut aussi incrémenter le compteur global dans la table Joueurs si tu en as un
            $upd = $ConnexionBDD->prepare("UPDATE Joueurs SET total_motm = total_motm + 1 WHERE id_joueur = ?");
            $upd->execute([$id_joueur]);

            echo json_encode(['succes' => true, 'message' => 'Vote MOTM enregistré !']);
        }
    } 
    
    else if ($action === "noter") {
        // Vérifier si l'IP a déjà noté CE joueur précis pour CE match
        $checkNote = $ConnexionBDD->prepare("SELECT id_vote FROM Votes WHERE id_match = ? AND ip_votant = ? AND id_joueur = ? AND note IS NOT NULL");
        $checkNote->execute([$id_match, $ip_votant, $id_joueur]);

        if ($checkNote->rowCount() > 0) {
            echo json_encode(['succes' => false, 'message' => 'Tu as déjà noté ce joueur !']);
        } else {
            // Insertion de la note avec le commentaire
            $ins = $ConnexionBDD->prepare("INSERT INTO Votes (id_match, id_joueur, ip_votant, note, commentaire) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$id_match, $id_joueur, $ip_votant, $note, $commentaire]);
            
            echo json_encode(['succes' => true, 'message' => 'Note de ' . $note . '/5 enregistrée avec succès !']);
        }
    }

} catch (PDOException $e) {
    echo json_encode(['succes' => false, 'message' => 'Erreur BDD : ' . $e->getMessage()]);
}
?>