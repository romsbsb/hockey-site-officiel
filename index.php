<?php
date_default_timezone_set('Europe/Paris');
// --- CONFIGURATION CONNEXION RAILWAY ---
$serveur = getenv('PGHOST') ?: "127.0.0.1";
$port    = getenv('PGPORT') ?: "5432";
$utilisateur = getenv('PGUSER') ?: "postgres";
$mot_de_passe = getenv('PGPASSWORD') ?: "hockey123";
$nom_base = getenv('PGDATABASE') ?: "Hockey";

try {
    $dsn = "pgsql:host=$serveur;port=$port;dbname=$nom_base";
    $ConnexionBDD = new PDO($dsn, $utilisateur, $mot_de_passe);
    $ConnexionBDD->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Récupération des matchs
    $reqMatchs = $ConnexionBDD->query("SELECT id_match, adversaire, statut, date_match, heure_match FROM Matchs ORDER BY date_match DESC");
    $liste_matchs = $reqMatchs->fetchAll(PDO::FETCH_ASSOC);

    if (empty($liste_matchs)) { die("Aucun match trouvé."); }

    $maintenant = new DateTime(); // Heure actuelle
    $match_actuel = null;

    // --- LOGIQUE D'AUTOMATISATION DU STATUT ---
    foreach ($liste_matchs as &$m) {
        $dateHeureMatch = new DateTime($m['date_match'] . ' ' . $m['heure_match']);
        $clotureTheorique = clone $dateHeureMatch;
        $clotureTheorique->modify('+4 hours');

        // Si l'heure actuelle a dépassé l'heure du match + 4h, on force le statut à 'Clôturé'
        if ($maintenant > $clotureTheorique) {
            $m['statut'] = 'Clôturé';
        }
    }
    unset($m); // Sécurité PHP

    // 2. Sélection du match à afficher (URL ou Aujourd'hui)
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

    // 3. Statut des votes (Ouverts entre 10h et 17h le jour du match, si non clôturé)
    $votes_ouverts = false;
    $heure_h = $maintenant->format('H:i');
    if ($match_actuel['date_match'] == $maintenant->format('Y-m-d') && $match_actuel['statut'] != 'Clôturé') {
        if ($heure_h >= "10:00" && $heure_h <= "17:00") {
            $votes_ouverts = true;
        }
    }

    // 4. Récupération des votes MOTM pour ce match précis
    // /!\ Modifie "Votes_MOTM" si ta table s'appelle autrement
    $reqMotm = $ConnexionBDD->prepare("SELECT id_joueur, COUNT(*) as nb_votes FROM Votes_MOTM WHERE id_match = ? GROUP BY id_joueur");
    $reqMotm->execute([$match_actuel['id_match']]);
    $votes_motm_match = $reqMotm->fetchAll(PDO::FETCH_KEY_PAIR); // Tableau [id_joueur => nb_votes]

    $max_votes = !empty($votes_motm_match) ? max($votes_motm_match) : 0;
    $motm_declare = ($match_actuel['statut'] == 'Clôturé' && $max_votes > 0);

    // 5. Données joueurs
    $req = $ConnexionBDD->query("SELECT * FROM Joueurs ORDER BY numero ASC");
    $tous_les_joueurs = $req->fetchAll(PDO::FETCH_ASSOC);

    $liste_titulaires = ['Matthias Renouf', 'Xavier Rousselet', 'Robin Mars', 'Kenny Burawudi', 'Timothy (C) Pihouee', 'Alexandre Hardy','Romeo Stab', 'Oscar Lagrange', 'Aurelien.H Hardy', 'Aurelien.B Berne', 'Maxime Lagrange'];
    
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
        body { font-family: sans-serif; background-color: #1a1a1a; color: white; margin: 0; text-align: center; padding-bottom: 50px; }
        header { background-color: #0A5C36; padding: 15px 0; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        .match-selector select { padding: 8px; border-radius: 20px; font-weight: bold; background: white; }
        .layout { display: flex; flex-wrap: wrap-reverse; justify-content: center; align-items: flex-start; gap: 20px; max-width: 1200px; margin: 20px auto; padding: 0 10px; }
        
        /* Terrain Hockey */
        .terrain { 
            background-color: #2e8b57; 
            flex: 2; 
            min-width: 320px; 
            max-width: 500px; 
            border: 3px solid white; 
            border-radius: 10px; 
            position: relative; 
            padding: 20px 0; 
            min-height: 750px; 
            display: flex; 
            flex-direction: column; 
            justify-content: space-around; 
            overflow: hidden;
        }
        .ligne-23m { position: absolute; width: 100%; height: 1px; border-top: 2px dashed rgba(255,255,255,0.6); z-index: 1; }
        .top-23 { top: 25%; }
        .bottom-23 { bottom: 25%; }
        .ligne-mediane { position: absolute; top: 50%; width: 100%; height: 2px; background: rgba(255,255,255,0.8); z-index: 1; }
        .cercle-tir { position: absolute; left: 50%; transform: translateX(-50%); width: 240px; height: 120px; border: 2px solid white; z-index: 0; }
        .top-cercle { top: -2px; border-radius: 0 0 120px 120px; }
        .bottom-cercle { bottom: -2px; border-radius: 120px 120px 0 0; }
        .cercle-central { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 60px; height: 60px; border: 2px solid rgba(255,255,255,0.6); border-radius: 50%; z-index: 1; }

        .banc { background: #2a2a2a; width: 120px; border: 2px dashed #555; border-radius: 10px; padding: 10px; }
        .panneau-motm { background: #2a2a2a; width: 220px; padding: 15px; border-radius: 10px; border: 2px solid #FFD700; }
        
        .ligne-joueurs { display: flex; justify-content: center; flex-wrap: wrap; gap: 10px; z-index: 2; }
        /* Ajout d'une marge en bas pour laisser la place aux badges de votes */
        .player-card { background: white; color: #333; border-radius: 12px; padding: 5px; width: 90px; cursor: pointer; position: relative; margin-bottom: 15px; transition: transform 0.2s; }
        .player-card:hover { transform: scale(1.05); }
        .player-photo { width: 60px; height: 60px; background: #ddd; border-radius: 50%; border: 2px solid #0A5C36; margin: 0 auto 5px auto; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 2rem; }
        .player-photo img { width: 100%; height: 100%; object-fit: cover; }
        .player-number { position: absolute; top: -5px; right: -5px; background: #dc3545; color: white; font-size: 0.7rem; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 1px solid white; }
        .player-rating { position: absolute; top: -5px; left: -5px; background: #FFD700; color: #000; font-size: 0.7rem; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 1px solid #333; }
        .player-name { font-size: 0.65rem; font-weight: bold; text-transform: uppercase; }

        /* Nouveaux styles pour les badges MOTM */
        .badge-votes { position: absolute; bottom: -12px; left: 50%; transform: translateX(-50%); background: #17a2b8; color: white; font-size: 0.7rem; font-weight: bold; padding: 2px 6px; border-radius: 8px; z-index: 10; white-space: nowrap; border: 1px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);}
        .badge-motm-winner { position: absolute; bottom: -12px; left: 50%; transform: translateX(-50%); background: #FFD700; color: #000; font-size: 0.7rem; font-weight: bold; padding: 2px 6px; border-radius: 8px; z-index: 10; white-space: nowrap; border: 1px solid #333; box-shadow: 0 2px 4px rgba(0,0,0,0.5); animation: pulse 1.5s infinite;}

        @keyframes pulse {
            0% { transform: translateX(-50%) scale(1); }
            50% { transform: translateX(-50%) scale(1.1); }
            100% { transform: translateX(-50%) scale(1); }
        }

        .btn-motm { background: #FFD700; color: black; border: none; padding: 10px; width: 100%; font-weight: bold; border-radius: 5px; cursor: pointer; margin-top: 10px; }
        .btn-motm:disabled { background: #555; color: #888; cursor: not-allowed; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); }
        .modal-content { background: #222; margin: 15% auto; padding: 20px; border-radius: 10px; width: 280px; }
        .stars { color: #555; font-size: 2rem; cursor: pointer; margin: 10px 0; }
        .star.active { color: #FFD700; }
        textarea { width: 90%; margin-top: 10px; padding: 5px; background: #333; color: white; border: 1px solid #555; }
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
        
        <?php if ($votes_ouverts): ?>
            <select id="select-motm" style="width:100%; padding:8px;">
                <option value="">-- Choisir --</option>
                <?php foreach($tous_les_joueurs as $j): ?>
                    <option value="<?= $j['id_joueur'] ?>"><?= htmlspecialchars($j['prenom'] . ' ' . $j['nom']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn-motm" onclick="validerMOTM()">VOTER POUR LE MOTM</button>
        <?php else: ?>
            <p style="color: #e74c3c; font-size: 0.9rem; margin-bottom: 15px;">Les votes sont fermés.</p>
            <?php if ($motm_declare): ?>
                <p style="color: #FFD700; font-weight: bold; font-size: 0.9rem;">Le gagnant a été désigné !</p>
            <?php endif; ?>
        <?php endif; ?>
        
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
</div>

<div id="modalNotation" class="modal">
    <div class="modal-content">
        <h2 id="modal-nom-joueur">Joueur</h2>
        <div class="stars" id="star-container">
            <span class="star" data-value="1">★</span>
            <span class="star" data-value="2">★</span>
            <span class="star" data-value="3">★</span>
            <span class="star" data-value="4">★</span>
            <span class="star" data-value="5">★</span>
        </div>
        <textarea id="commentaire-performance" placeholder="Commentaire (optionnel)"></textarea>
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

function resetStars() {
    noteVal = 0;
    document.querySelectorAll('.star').forEach(s => s.classList.remove('active'));
    document.getElementById('commentaire-performance').value = "";
}

function enregistrerNote() {
    if(noteVal == 0) { alert("Sélectionne une note !"); return; }
    envoyerAction(joueurId, "noter", noteVal, document.getElementById('commentaire-performance').value);
    fermerModal();
}

function validerMOTM() {
    if (!votesOuverts) { alert("Les votes pour le MOTM sont fermés."); return; }
    const id = document.getElementById('select-motm').value;
    if(!id) { alert("Choisis un joueur !"); return; }
    envoyerAction(id, "vote_motm");
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