<?php
date_default_timezone_set('Europe/Paris');

// --- NOUVEAU : SYSTÈME DE COOKIE POUR LE WI-FI ---
// Si le visiteur n'a pas encore de ticket (cookie)
if (!isset($_COOKIE['hscsm_voter_id'])) {
    // On lui crée un identifiant unique aléatoire
    $voter_id = bin2hex(random_bytes(16));
    // On sauvegarde ce ticket sur son navigateur pour 1 an
    setcookie('hscsm_voter_id', $voter_id, time() + (86400 * 365), "/");
    $_COOKIE['hscsm_voter_id'] = $voter_id; // On l'active immédiatement pour ce chargement
}
// --- CONFIGURATION CONNEXION RAILWAY ---
$serveur = getenv('PGHOST') ?: "127.0.0.1";
$port    = getenv('PGPORT') ?: "5432";
$utilisateur = getenv('PGUSER') ?: "postgres";
$mot_de_passe = getenv('PGPASSWORD') ?: "hockey123";
$nom_base = getenv('PGDATABASE') ?: "Hockey";

try {
    // 1. D'ABORD : On crée la connexion à la base de données
    $dsn = "pgsql:host=$serveur;port=$port;dbname=$nom_base";
    $ConnexionBDD = new PDO($dsn, $utilisateur, $mot_de_passe);
    $ConnexionBDD->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. Ensuite : Récupération de tous les matchs
    $reqMatchs = $ConnexionBDD->query("SELECT id_match, adversaire, statut, date_match, heure_match FROM Matchs ORDER BY date_match DESC");
    $liste_matchs = $reqMatchs->fetchAll(PDO::FETCH_ASSOC);

    if (empty($liste_matchs)) { die("Aucun match trouvé."); }

    $maintenant = new DateTime(); // Heure actuelle
    $match_actuel = null;

    // --- LOGIQUE D'AUTOMATISATION DU STATUT ET DISTRIBUTION DU MOTM ---
    foreach ($liste_matchs as &$m) {
        $dateHeureMatch = new DateTime($m['date_match'] . ' ' . $m['heure_match']);
        $clotureTheorique = clone $dateHeureMatch;
        $clotureTheorique->modify('+4 hours');

        // 1. Passage au statut "En cours" pendant la fenêtre de 4 heures
        // On vérifie que le statut n'est pas déjà "En cours" ou "Clôturé" pour ne pas spammer la base de données
        if ($maintenant >= $dateHeureMatch && $maintenant <= $clotureTheorique && $m['statut'] !== 'En cours' && $m['statut'] !== 'Clôturé') {
            
            $updateMatch = $ConnexionBDD->prepare("UPDATE Matchs SET statut = 'En cours' WHERE id_match = ?");
            $updateMatch->execute([$m['id_match']]);
            $m['statut'] = 'En cours'; // Mise à jour de l'affichage instantané
        }

        // 2. Passage au statut "Clôturé" (après les 4 heures) et distribution des points MOTM
        if ($maintenant > $clotureTheorique && $m['statut'] !== 'Clôturé') {
            
            // On verrouille définitivement le match
            $updateMatch = $ConnexionBDD->prepare("UPDATE Matchs SET statut = 'Clôturé' WHERE id_match = ?");
            $updateMatch->execute([$m['id_match']]);
            $m['statut'] = 'Clôturé';

            // On calcule qui a gagné en comptant les votes dans votes_motm
            $reqGagnant = $ConnexionBDD->prepare("
                SELECT id_joueur, COUNT(*) as nb_votes 
                FROM votes_motm 
                WHERE id_match = ? 
                GROUP BY id_joueur 
                ORDER BY nb_votes DESC
            ");
            $reqGagnant->execute([$m['id_match']]);
            $resultats = $reqGagnant->fetchAll(PDO::FETCH_ASSOC);

            // On distribue la récompense (+1 au Hall of Fame)
            if (!empty($resultats)) {
                $max_votes = $resultats[0]['nb_votes'];
                
                $updateJoueur = $ConnexionBDD->prepare("UPDATE Joueurs SET total_motm = total_motm + 1 WHERE id_joueur = ?");
                
                // Gestion des égalités : on boucle sur les premiers
                foreach ($resultats as $res) {
                    if ($res['nb_votes'] == $max_votes) {
                        $updateJoueur->execute([$res['id_joueur']]);
                    } else {
                        break; // On s'arrête dès qu'un joueur a moins de votes que le maximum
                    }
                }
            }
        }
    }
    unset($m); // Sécurité PHP
    // 3. Sélection du match actuel (URL ou Aujourd'hui)
    if (isset($_GET['id_match'])) {
        $id_req = (int)$_GET['id_match'];
        foreach($liste_matchs as $m) {
            if($m['id_match'] == $id_req) { $match_actuel = $m; break; }
        }
    }
    if (!$match_actuel) {
        foreach($liste_matchs as $m) {
            if($m['date_match'] == $maintenant->format('Y-m-d')) { $match_actuel = $m; break; }
        }
    }
    if (!$match_actuel) { $match_actuel = $liste_matchs[0]; }

    // 4. MAINTENANT : On peut compter les votes car on est connecté et on a le match actuel !
    $reqVotesActuels = $ConnexionBDD->prepare("SELECT id_joueur, COUNT(*) as nb_votes FROM Votes WHERE id_match = ? AND note IS NULL GROUP BY id_joueur");
    $reqVotesActuels->execute([$match_actuel['id_match']]);
    $decompte_votes = $reqVotesActuels->fetchAll(PDO::FETCH_KEY_PAIR); // Donne un tableau [id_joueur => nombre_de_votes]

   // 5. Statut des votes (Ouverts pendant 4h à partir du coup d'envoi)
    $votes_ouverts = false;
    
    //On combine la date et l'heure du match en un seul objet
    $debutMatch = new DateTime($match_actuel['date_match'] . ' ' . $match_actuel['heure_match']);
    
    // On calcule l'heure de fin (début + 4 heures)
    $finVote = clone $debutMatch;
    $finVote->modify('+4 hours');

    // Si l'heure actuelle est comprise entre le début du match et la fin des 4h
    if ($maintenant >= $debutMatch && $maintenant <= $finVote) {
        $votes_ouverts = true;
    }

    // 6. Récupération des votes MOTM pour ce match précis
    $reqMotm = $ConnexionBDD->prepare("SELECT id_joueur, COUNT(*) as nb_votes FROM Votes_MOTM WHERE id_match = ? GROUP BY id_joueur");
    $reqMotm->execute([$match_actuel['id_match']]);
    $votes_motm_match = $reqMotm->fetchAll(PDO::FETCH_KEY_PAIR);

    $max_votes = !empty($votes_motm_match) ? max($votes_motm_match) : 0;
    $motm_declare = ($match_actuel['statut'] == 'Clôturé' && $max_votes > 0);

    // 7. Données joueurs
    $req = $ConnexionBDD->query("SELECT * FROM Joueurs ORDER BY numero ASC");
    $tous_les_joueurs = $req->fetchAll(PDO::FETCH_ASSOC);

    $liste_titulaires = ['Matthias Renouf', 'Xavier Rousselet', 'Robin Mars', 'Kenny Burawudi', 'Timothy (C) Pihouee', 'Alexandre Hardy','Romeo Stab', 'Oscar Lagrange', 'Aurélien.H Hardy', 'Clement.V Vidal', 'Maxime Lagrange'];
    
    $equipe = ['Attaquant' => [], 'Milieu' => [], 'Defenseur' => [], 'Gardien' => []];
    $remplacants = [];
    $hall_of_fame = [];

    foreach ($tous_les_joueurs as $j) {
        if ($j['total_motm'] > 0) $hall_of_fame[] = $j;
        $nom_c = $j['prenom'] . ' ' . $j['nom'];
        if (in_array($nom_c, $liste_titulaires)) { $equipe[$j['poste']][] = $j; } 
        else { $remplacants[] = $j; }
    }

    $reqMoy = $ConnexionBDD->prepare("SELECT id_joueur, ROUND(AVG(note), 1) FROM Votes WHERE id_match = ? AND note IS NOT NULL GROUP BY id_joueur");
    $reqMoy->execute([$match_actuel['id_match']]);
    $moyennes = $reqMoy->fetchAll(PDO::FETCH_KEY_PAIR);

} catch(PDOException $e) { die("Erreur : " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Homme du Match | HSCSM</title>
   <style>
    /* --- BASES & LAYOUT --- */
    body { font-family: sans-serif; background-color: #1a1a1a; color: white; margin: 0; text-align: center; padding-bottom: 50px; }
    header { background-color: #0A5C36; padding: 15px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
    .match-selector select { padding: 8px; border-radius: 20px; font-weight: bold; background: white; }
    .layout { display: flex; flex-wrap: wrap-reverse; justify-content: center; align-items: flex-start; gap: 20px; max-width: 1200px; margin: 20px auto; padding: 0 10px; }
    
    /* --- TERRAIN HOCKEY --- */
    .terrain { 
        background-color: #2e8b57; flex: 2; min-width: 320px; max-width: 500px; 
        border: 3px solid white; border-radius: 10px; position: relative; 
        padding: 20px 0; min-height: 750px; display: flex; 
        flex-direction: column; justify-content: space-around; overflow: hidden;
    }
    .ligne-23m { position: absolute; width: 100%; height: 1px; border-top: 2px dashed rgba(255,255,255,0.6); z-index: 1; }
    .top-23 { top: 25%; }
    .bottom-23 { bottom: 25%; }
    .ligne-mediane { position: absolute; top: 50%; width: 100%; height: 2px; background: rgba(255,255,255,0.8); z-index: 1; }
    .cercle-tir { position: absolute; left: 50%; transform: translateX(-50%); width: 240px; height: 120px; border: 2px solid white; z-index: 0; }
    .top-cercle { top: -2px; border-radius: 0 0 120px 120px; }
    .bottom-cercle { bottom: -2px; border-radius: 120px 120px 0 0; }
    .cercle-central { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 60px; height: 60px; border: 2px solid rgba(255,255,255,0.6); border-radius: 50%; z-index: 1; }

    /* --- PANNEAUX LATERAUX --- */
    .banc { background: #2a2a2a; width: 120px; border: 2px dashed #555; border-radius: 10px; padding: 10px; }
    .panneau-motm { background: #2a2a2a; width: 220px; padding: 15px; border-radius: 10px; border: 2px solid #FFD700; }

    /* --- SYSTEME D'ÉTOILES (AFFICHAGE STATIQUE SUR TERRAIN) --- */
    .star-rating { display: inline-block; font-size: 0.8rem; color: #444; position: relative; }
    .star-rating::before { content: '★★★★★'; letter-spacing: 1px; }
    .star-rating-fill { color: #FFD700; position: absolute; top: 0; left: 0; overflow: hidden; white-space: nowrap; }
    .star-rating-fill::before { content: '★★★★★'; letter-spacing: 1px; }

    /* --- SYSTEME DE NOTATION (MODAL CLIQUEABLE) --- */
    .rating-group { display: inline-flex; flex-direction: row-reverse; justify-content: center; padding: 10px 0; }
    .rating-group input { display: none !important; }
    .rating-group label {
        cursor: pointer; position: relative; width: 20px; height: 40px; 
        font-size: 40px; line-height: 40px; color: #444; overflow: hidden;
    }
    .rating-group label::before { content: '★'; position: absolute; top: 0; width: 40px; }
    .rating-group label.half::before { left: 0; }
    .rating-group label.full::before { left: -20px; }
    .rating-group label.full { margin-right: 5px; }

    /* Animation de sélection dorée */
    .rating-group input:checked ~ label,
    .rating-group label:hover,
    .rating-group label:hover ~ label { color: #FFD700 !important; }

    /* --- CARTES JOUEURS --- */
    .ligne-joueurs { display: flex; justify-content: center; flex-wrap: wrap; gap: 10px; z-index: 2; }
    .player-card { background: white; color: #333; border-radius: 12px; padding: 5px; width: 90px; cursor: pointer; position: relative; margin-bottom: 15px; transition: transform 0.2s; }
    .player-card:hover { transform: scale(1.05); }
    .player-photo { width: 60px; height: 60px; background: #ddd; border-radius: 50%; border: 2px solid #0A5C36; margin: 0 auto 5px auto; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 2rem; }
    .player-photo img { width: 100%; height: 100%; object-fit: cover; }
    .player-number { position: absolute; top: -5px; right: -5px; background: #dc3545; color: white; font-size: 0.7rem; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 1px solid white; }
    .player-rating { position: absolute; top: -5px; left: -5px; background: #FFD700; color: #000; font-size: 0.7rem; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 1px solid #333; }
    .player-name { font-size: 0.65rem; font-weight: bold; text-transform: uppercase; }

    /* --- BADGES & BOUTONS --- */
    .badge-votes { position: absolute; bottom: -12px; left: 50%; transform: translateX(-50%); background: #17a2b8; color: white; font-size: 0.7rem; font-weight: bold; padding: 2px 6px; border-radius: 8px; z-index: 10; white-space: nowrap; border: 1px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);}
    .badge-motm-winner { position: absolute; bottom: -12px; left: 50%; transform: translateX(-50%); background: #FFD700; color: #000; font-size: 0.7rem; font-weight: bold; padding: 2px 6px; border-radius: 8px; z-index: 10; white-space: nowrap; border: 1px solid #333; box-shadow: 0 2px 4px rgba(0,0,0,0.5); animation: pulse 1.5s infinite;}

    @keyframes pulse {
        0% { transform: translateX(-50%) scale(1); }
        50% { transform: translateX(-50%) scale(1.1); }
        100% { transform: translateX(-50%) scale(1); }
    }

    .btn-motm { background: #FFD700; color: black; border: none; padding: 10px; width: 100%; font-weight: bold; border-radius: 5px; cursor: pointer; margin-top: 10px; }
    .btn-motm:disabled { background: #555; color: #888; cursor: not-allowed; }
    .btn-modifier { background: #17a2b8; color: white; border: 1px solid #117a8b; margin-top: 5px; }
    .btn-modifier:disabled { background: #555; color: #888; border: none; }

    /* --- MODAL & MISC --- */
    .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); }
    .modal-content { background: #222; margin: 15% auto; padding: 20px; border-radius: 10px; width: 280px; }
    textarea { width: 90%; margin-top: 10px; padding: 5px; background: #333; color: white; border: 1px solid #555; border-radius: 4px; }
    #confirmation-msg { display: none; position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #28a745; color: white; padding: 15px 25px; border-radius: 30px; z-index: 2000; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
</style>
</head>
<body>

<header>
    <h1>HSCSM - Homme du Match</h1>
    <div class="match-selector">
        <select onchange="window.location.href='?id_match='+this.value">
            <?php foreach($liste_matchs as $m): ?>
                <option value="<?= $m['id_match'] ?>" <?= ($match_actuel['id_match'] == $m['id_match']) ? 'selected' : '' ?>>
                    Vs <?= htmlspecialchars($m['adversaire']) ?> (<?= $m['statut'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</header>

<p id="statut-vote" style="margin-top: 20px;">
    <?php if ($votes_ouverts): ?>
        <span style="color: #2ecc71; font-weight: bold;">🟢 VOTES OUVERTS</span>
    <?php else: ?>
        <span style="color: #e74c3c; font-weight: bold;">🔴 VOTES FERMÉS</span>
    <?php endif; ?>
</p>

<div class="layout">
    <div class="banc">
        <h3 style="font-size:0.7rem; color:#aaa;">🔄 REMPLAÇANTS</h3>
        <div class="ligne-joueurs" style="flex-direction: column;">
            <?php foreach ($remplacants as $j): ?>
                <div class="player-card" onclick="ouvrirModal(<?= $j['id_joueur'] ?>, '<?= addslashes($j['prenom']) ?>')">
                    <div class="player-number"><?= $j['numero'] ?></div>
                    <?php if (isset($moyennes[$j['id_joueur']])): ?>
                        <div class="player-rating"><?= $moyennes[$j['id_joueur']] ?></div>
                    <?php endif; ?>
                    <div class="player-photo">
                        <?php if (!empty($j['photo']) && file_exists("photos/".$j['photo'])): ?>
                            <img src="photos/<?= $j['photo'] ?>">
                        <?php else: ?>👤<?php endif; ?>
                    </div>
                    <div class="player-name"><?= htmlspecialchars($j['prenom']) ?></div>

<?php if (isset($moyennes[$j['id_joueur']])): 
    // On calcule le pourcentage de remplissage (ex: 4.2 / 5 * 100 = 84%)
    $pourcentage_etoiles = ($moyennes[$j['id_joueur']] / 5) * 100;
?>
    <div class="star-rating" title="Moyenne : <?= $moyennes[$j['id_joueur']] ?>/5">
        <div class="star-rating-fill" style="width: <?= $pourcentage_etoiles ?>%;"></div>
    </div>
<?php endif; ?>

                    <?php 
                    $nb_votes = $votes_motm_match[$j['id_joueur']] ?? 0;
                    if ($motm_declare && $nb_votes > 0 && $nb_votes == $max_votes): ?>
                        <div class="badge-motm-winner">🏆 MOTM</div>
                    <?php elseif ($nb_votes > 0): ?>
                        <div class="badge-votes">🗳️ <?= $nb_votes ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="terrain">
        <div class="cercle-tir top-cercle"></div>
        <div class="ligne-23m top-23"></div>
        <div class="ligne-mediane"></div>
        <div class="cercle-central"></div>
        <div class="ligne-23m bottom-23"></div>
        <div class="cercle-tir bottom-cercle"></div>
        
        <?php foreach (['Attaquant', 'Milieu', 'Defenseur', 'Gardien'] as $poste): ?>
            <div class="ligne-joueurs">
                <?php foreach (($equipe[$poste] ?? []) as $j): ?>
                    <div class="player-card" onclick="ouvrirModal(<?= $j['id_joueur'] ?>, '<?= addslashes($j['prenom']) ?>')">
                        <div class="player-number"><?= $j['numero'] ?></div>
                        <?php if (isset($moyennes[$j['id_joueur']])): ?>
                            <div class="player-rating"><?= $moyennes[$j['id_joueur']] ?></div>
                        <?php endif; ?>
                        <div class="player-photo">
                            <?php if (!empty($j['photo']) && file_exists("photos/".$j['photo'])): ?>
                                <img src="photos/<?= $j['photo'] ?>">
                            <?php else: ?>👤<?php endif; ?>
                        </div>
                        <div class="player-name"><?= htmlspecialchars($j['prenom']) ?></div>

<?php if (isset($moyennes[$j['id_joueur']])): 
    // On calcule le pourcentage de remplissage (ex: 4.2 / 5 * 100 = 84%)
    $pourcentage_etoiles = ($moyennes[$j['id_joueur']] / 5) * 100;
?>
    <div class="star-rating" title="Moyenne : <?= $moyennes[$j['id_joueur']] ?>/5">
        <div class="star-rating-fill" style="width: <?= $pourcentage_etoiles ?>%;"></div>
    </div>
<?php endif; ?>

                        <?php 
                        $nb_votes = $votes_motm_match[$j['id_joueur']] ?? 0;
                        if ($motm_declare && $nb_votes > 0 && $nb_votes == $max_votes): ?>
                            <div class="badge-motm-winner">🏆 MOTM</div>
                        <?php elseif ($nb_votes > 0): ?>
                            <div class="badge-votes">🗳️ <?= $nb_votes ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="panneau-motm">
    <h3>🏆 VOTE MOTM</h3>
    
    <select id="select-motm" style="width:100%; padding:8px;" <?= !$votes_ouverts ? 'disabled' : '' ?>>
        <option value="">-- Choisir --</option>
        <?php foreach($tous_les_joueurs as $j): ?>
            <?php 
                // Récupère le nombre de votes ou met 0 par défaut
                $nb = isset($decompte_votes[$j['id_joueur']]) ? $decompte_votes[$j['id_joueur']] : 0; 
            ?>
            <option value="<?= $j['id_joueur'] ?>">
                <?= htmlspecialchars($j['prenom'] . ' ' . $j['nom']) ?> (nb votes : <?= $nb ?>)
            </option>
        <?php endforeach; ?>
    </select>
    
    <button class="btn-motm" onclick="validerMOTM()" <?= !$votes_ouverts ? 'disabled style="background:#555; cursor:not-allowed;"' : '' ?>>
        <?= $votes_ouverts ? 'VOTER POUR LE MOTM' : 'VOTES FERMÉS' ?>
    </button>

    <button class="btn-motm btn-modifier" onclick="modifierMOTM()" <?= !$votes_ouverts ? 'disabled style="background:#555; cursor:not-allowed;"' : '' ?>>
        <?= $votes_ouverts ? 'MODIFIER MON VOTE' : 'VOTES FERMÉS' ?>
    </button>
    
    <div style="margin-top:20px; text-align: left; font-size: 0.8rem;">
        <h4 style="border-bottom: 1px solid #444;">📜 Hall of Fame</h4>
        <?php foreach(array_slice($hall_of_fame, 0, 5) as $h): ?>
            <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                <span><?= htmlspecialchars($h['prenom']) ?></span>
                <span style="color:#FFD700;">★ <?= $h['total_motm'] ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="modalNotation" class="modal">
    <div class="modal-content">
        <h2 id="modal-nom-joueur" style="margin-bottom:10px;">Joueur</h2>
        
        <div class="rating-group">
    <input type="radio" id="st5" name="note_radio" value="5">
    <label for="st5" class="full"></label>
    <input type="radio" id="st45" name="note_radio" value="4.5">
    <label for="st45" class="half"></label>

    <input type="radio" id="st4" name="note_radio" value="4">
    <label for="st4" class="full"></label>
    <input type="radio" id="st35" name="note_radio" value="3.5">
    <label for="st35" class="half"></label>

    <input type="radio" id="st3" name="note_radio" value="3">
    <label for="st3" class="full"></label>
    <input type="radio" id="st25" name="note_radio" value="2.5">
    <label for="st25" class="half"></label>

    <input type="radio" id="st2" name="note_radio" value="2">
    <label for="st2" class="full"></label>
    <input type="radio" id="st15" name="note_radio" value="1.5">
    <label for="st15" class="half"></label>

    <input type="radio" id="st1" name="note_radio" value="1">
    <label for="st1" class="full"></label>
    <input type="radio" id="st05" name="note_radio" value="0.5">
    <label for="st05" class="half"></label>
</div>

        <textarea id="commentaire-performance" placeholder="Commentaire (optionnel)" style="width:100%; box-sizing:border-box;"></textarea>
        
        <button class="btn-motm" onclick="enregistrerNote()">ENREGISTRER</button>
        <button onclick="fermerModal()" style="background:none; border:none; color:#aaa; cursor:pointer; margin-top:10px;">Annuler</button>
    </div>
</div>
<div id="confirmation-msg"></div>

<script>
let joueurId = null;
let noteVal = 0;
const votesOuverts = <?= json_encode($votes_ouverts) ?>;

function ouvrirModal(id, prenom) {
    if (!votesOuverts) { alert("Les notations sont fermées."); return; }
    joueurId = id;
    document.getElementById('modal-nom-joueur').innerText = "Noter " + prenom;
    document.getElementById('modalNotation').style.display = 'block';
    resetStars();
}

function fermerModal() { document.getElementById('modalNotation').style.display = 'none'; }

document.querySelectorAll('.star').forEach(star => {
    star.onclick = function() {
        noteVal = this.dataset.value;
        document.querySelectorAll('.star').forEach(s => s.classList.toggle('active', s.dataset.value <= noteVal));
    }
});

// Remplacez vos fonctions existantes par celles-ci :
function resetStars() {
    // Décoche tous les boutons radio
    document.querySelectorAll('input[name="note_radio"]').forEach(radio => radio.checked = false);
    document.getElementById('commentaire-performance').value = "";
}

function enregistrerNote() {
    // On récupère le bouton radio coché
    const selected = document.querySelector('input[name="note_radio"]:checked');
    
    if(!selected) { 
        alert("Sélectionne une note !"); 
        return; 
    }
    
    const noteVal = selected.value; // ex: "4.5"
    const commentaire = document.getElementById('commentaire-performance').value;
    
    envoyerAction(joueurId, "noter", noteVal, commentaire);
    fermerModal();
}

function validerMOTM() {
    if (!votesOuverts) { alert("Les votes pour le MOTM sont fermés."); return; }
    const id = document.getElementById('select-motm').value;
    if(!id) { alert("Choisis un joueur !"); return; }
    envoyerAction(id, "vote_motm");
}

// NOUVELLE FONCTION : Modifier le MOTM
function modifierMOTM() {
    if (!votesOuverts) { alert("Les votes pour le MOTM sont fermés."); return; }
    const id = document.getElementById('select-motm').value;
    if(!id) { alert("Choisis le nouveau joueur pour lequel tu souhaites voter !"); return; }
    envoyerAction(id, "modifier_motm");
}

function envoyerAction(idJ, action, note = null, commentaire = "") {
    fetch('traiter_vote.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id_joueur: idJ,
            id_match: <?= $match_actuel['id_match'] ?>,
            action: action,
            note: note,
            commentaire: commentaire
        })
    })
    .then(r => r.json())
    .then(data => {
        const msg = document.getElementById('confirmation-msg');
        msg.innerText = data.message;
        msg.style.display = 'block';
        setTimeout(() => { 
            msg.style.display = 'none'; 
            if(data.succes) location.reload();
        }, 2000);
    });
}
</script>
</body>
</html>