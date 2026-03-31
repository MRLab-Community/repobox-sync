<?php
// Disabilita cache per garantire sicurezza logout su mobile
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

session_start();

// --- RINNOVO SESSIONE PERSISTENTE ---
if (isset($_SESSION['user_id'])) {
    $session_lifetime = 30 * 24 * 60 * 60; 
    session_set_cookie_params([
        'lifetime' => $session_lifetime,
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_commit();
}

require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Gestione salvataggio dati (invariato)
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // ... (tutto il blocco POST invariato) ...
        if (isset($_POST['action']) && $_POST['action'] == 'reset') {
            $stmt = $pdo->prepare("DELETE FROM smoke_data WHERE user_id = ?");
            $stmt->execute([$user_id]);
            header("Location: index.php");
            exit;
        } else {
            $date = $_POST['start_date'];
            $cost = floatval($_POST['pack_cost']);
            $count = intval($_POST['cigs_per_day']);
            $stmt = $pdo->prepare("SELECT id FROM smoke_data WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                $upd = $pdo->prepare("UPDATE smoke_data SET last_cig_date=?, pack_cost=?, cigs_per_day=? WHERE user_id=?");
                $upd->execute([$date, $cost, $count, $user_id]);
            } else {
                $ins = $pdo->prepare("INSERT INTO smoke_data (user_id, last_cig_date, pack_cost, cigs_per_day) VALUES (?, ?, ?, ?)");
                $ins->execute([$user_id, $date, $cost, $count]);
            }
            header("Location: index.php");
            exit;
        }
    }

    // --- QUERY MODIFICATA QUI ---
    // Selezioniamo sia i dati smoke_data (s.*) che crises_won dalla tabella users (u.crises_won)
    $stmt = $pdo->prepare("SELECT s.*, u.crises_won FROM smoke_data s LEFT JOIN users u ON s.user_id = u.id WHERE s.user_id = ?");
    $stmt->execute([$user_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    $stats = [];
    if ($data) {
        $start = new DateTime($data['last_cig_date']);
        $now = new DateTime();
        $interval = $start->diff($now);
        $total_days = $interval->days;
        $total_hours = ($total_days * 24) + $interval->h;
        $saved_money = ($total_days * $data['cigs_per_day'] / 20) * $data['pack_cost'];
        $stats = [
            'days' => $total_days,
            'hours' => $total_hours,
            'money' => number_format($saved_money, 2),
            'cigs_avoided' => $total_days * $data['cigs_per_day']
        ];
        
        // --- AGGIUNTA VARIABILE $USER ---
        // Il blocco HTML sotto si aspetta $user['crises_won'], quindi lo creiamo qui
        $user = [
            'crises_won' => $data['crises_won'] ?? 0
        ]; 
        // ------------------------------
    }

    // --- LOGICA MESSAGGIO GIORNALIERO ---
    $dayOfYear = (int)date('z') + 1;
    
    // Creiamo un array $user compatibile con il nuovo blocco HTML
$user = ['crises_won' => $data['crises_won'] ?? 0 ];
    
    $dailyQuotes = [
        "Ogni nuovo mattino è una pagina bianca: tu sei l'autore della tua storia di libertà.",
        "La forza non risiede nel non cadere mai, ma nel rialzarsi ogni volta con più consapevolezza.",
        "Il coraggio non è l'assenza di paura, ma la capacità di agire nonostante essa.",
        "Oggi scegli te stesso. Ogni respiro libero è una vittoria silenziosa ma potente.",
        "Non guardare quanto manca alla vetta, guarda quanto strada hai già fatto con orgoglio.",
        "La tua volontà è più forte di qualsiasi abitudine. Ricordalo in questo istante.",
        "Ogni tentazione superata scolpisce la versione migliore di te che sta emergendo.",
        "La libertà non è un destino, è una scelta che rinnovi ogni singolo giorno.",
        "Sei più forte di quanto credi e più capace di quanto immagini. Continua a camminare.",
        "Il passato è lezione, il futuro è speranza, il presente è il tuo potere.",
        "Respira profondamente: stai guarendo, stai cambiando, stai vincendo.",
        "La disciplina è il ponte tra i tuoi obiettivi e la loro realizzazione.",
        "Non sei definito dalle tue cadute, ma dalla decisione di non fumare oggi.",
        "Ogni ora senza fumo è un mattone nella costruzione della tua nuova vita.",
        "La luce filtra sempre attraverso le nuvole più scure. Fidati del processo.",
        "Sei il guardiano della tua salute. Proteggila con orgoglio e determinazione.",
        "Il cambiamento inizia nel momento in cui decidi di essere diverso.",
        "La tua mente è un giardino: coltiva pensieri di forza e raccogli frutti di salute.",
        "Non serve essere perfetti, serve essere costanti. Oggi ci sei.",
        "Ascolta il tuo corpo: ti sta ringraziando silenziosamente per questa scelta.",
        "La vera libertà è non dover più chiedere permesso alle proprie debolezze.",
        "Ogni giorno è un'opportunità per riscrivere la tua storia di salute.",
        "La pazienza è amara, ma il suo frutto è dolcissimo. Continua ad aspettare il meglio.",
        "Sei capitano della tua anima. Guida la nave verso acque più limpide.",
        "La forza di volontà è come un muscolo: più la usi, più diventa potente.",
        "Non contare i giorni, fai in modo che i giorni contino. Vivi pienamente.",
        "Il successo è la somma di piccoli sforzi ripetuti giorno dopo giorno.",
        "Oggi scegli la vita. Scegli la leggerezza di chi ha vinto una battaglia interiore.",
        "La tua resilienza è la tua arma più potente contro le vecchie abitudini.",
        "Ricorda perché hai iniziato quando senti la voglia di mollare.",
        "Sei un esempio di forza per te stesso e per chi ti osserva.",
        "La guarigione non è lineare, ma ogni passo avanti è definitivo.",
        "Trasforma il desiderio di fumo in energia per i tuoi nuovi obiettivi.",
        "Il sole sorge anche dopo la notte più buia. La tua luce sta tornando.",
        "Sii gentile con te stesso, ma fermo nei tuoi principi.",
        "La libertà ha un sapore dolce che solo chi ha lottato può gustare.",
        "Ogni rifiuto alla sigaretta è un 'sì' detto alla tua felicità futura.",
        "La tua identità non è 'ex fumatore', ma 'persona libera'.",
        "Visualizza la persona che vuoi diventare e agisci come lei farebbe oggi.",
        "Le difficoltà sono solo scalini per salire più in alto.",
        "Il controllo è nelle tue mani. Non restituirlo a un oggetto.",
        "Celebra le piccole vittorie: sono i semi dei grandi traguardi.",
        "La tua salute è il tuo patrimonio più prezioso. Investici ogni giorno.",
        "Non sei solo in questo viaggio. Milioni di persone hanno vinto prima di te.",
        "La costanza batte l'intensità. Piccoli passi quotidiani portano lontano.",
        "Oggi è il giorno perfetto per continuare a non fumare.",
        "La tua mente può creare ostacoli o ponti. Scegli i ponti.",
        "Resisti all'impulso: durerà pochi minuti, l'orgoglio durerà per sempre.",
        "Sei architetto del tuo destino. Costruisci fondamenta solide.",
        "La pace interiore vale infinitamente più di un momento di piacere effimero.",
        "Guardati allo specchio e ringrazia la persona che vedi per la sua forza.",
        "Il tempo passa comunque: fallo passare mentre diventi migliore.",
        "La libertà è sentire l'aria nei polmoni senza senso di colpa.",
        "Ogni giorno pulito è un regalo che fai al tuo io futuro.",
        "La sfida di oggi è la forza di domani.",
        "Non permettere a un'abitudine passata di rubare il tuo presente.",
        "Sei più grande delle tue voglie. Dominale con la consapevolezza.",
        "La vita ti sorride quando scegli di sorridere alla vita.",
        "Il rispetto per se stessi è la forma più alta di amore.",
        "Ogni respiro è un promemoria: sei vivo, sei libero, sei forte.",
        "Trasforma la noia in creatività e lo stress in azione positiva.",
        "La tua storia di successo si scrive con le scelte di oggi.",
        "Non cercare scorciatoie: la via maestra della volontà è la più sicura.",
        "Sei un guerriero della salute. La tua armatura è la determinazione.",
        "Il benessere è uno stato mentale prima che fisico. Credici.",
        "Ogni volta che dici 'no' al fumo, dici 'sì' ai tuoi sogni.",
        "La chiarezza mentale che stai guadagnando è un tesoro inestimabile.",
        "Cammina a testa alta: stai conquistando la tua indipendenza.",
        "Le tempeste passano, le radici forti restano. Tu sei quelle radici.",
        "Sostituisci il vecchio rituale con nuove abitudini di gioia.",
        "La tua energia vitale sta tornando a fluire liberamente.",
        "Oggi è un altro tassello del mosaico della tua nuova vita.",
        "Non sottovalutare il potere di un giorno alla volta.",
        "La gratitudine per la tua salute è il miglior antidoto alla nostalgia.",
        "Sei il protagonista di un film di rinascita. Recita la parte al meglio.",
        "La forza nasce dal silenzio interiore e dall'ascolto di sé.",
        "Ogni scelta sana è un atto di rivoluzione personale.",
        "Il futuro ti ringrazierà per la disciplina di oggi.",
        "Non sei schiavo dei tuoi impulsi. Sei il loro maestro.",
        "La bellezza della libertà è che nessuno può portartela via.",
        "Riconosci i trigger, accettali e lasciali scorrere via come nuvole.",
        "La tua vita sta acquisendo nuovi colori e nuove sfumature.",
        "Ogni minuto di resistenza è un investimento sul tuo benessere.",
        "Sei capace di cose straordinarie. Questo percorso ne è la prova.",
        "La serenità arriva quando accetti di aver fatto la scelta giusta.",
        "Non guardare indietro con rimpianto, guarda avanti con speranza.",
        "La tua determinazione è la luce che guida i tuoi passi.",
        "Oggi scegli di onorare il corpo che ti accompagna nel viaggio.",
        "Le abitudini si cambiano un pensiero alla volta.",
        "Sei degno di una vita piena, sana e libera.",
        "Il coraggio di cambiare è il primo passo verso la felicità.",
        "Ogni giorno senza fumo è una dichiarazione di indipendenza.",
        "La tua forza interiore è una risorsa inesauribile.",
        "Fidati del processo: il meglio deve ancora venire.",
        "Sei tu che decidi come finisce la tua storia.",
        "La libertà è un diritto che hai riconquistato.",
        "Ogni respiro profondo è un abbraccio alla vita.",
        "La tua volontà di ferro sta plasmando un nuovo destino.",
        "Non arrenderti ora: sei più vicino di quanto pensi.",
        "La salute è la corona che ti sei costruito giorno per giorno.",
        "Sii orgoglioso di ogni ora conquistata.",
        "La tua mente è libera, il tuo corpo ti segue.",
        "Oggi è il giorno per brillare di luce propria.",
        "Le sfide ti rendono più forte, non più debole.",
        "La tua perseveranza è ispirazione per gli altri.",
        "Vivi il presente con la consapevolezza di chi ha scelto la vita.",
        "Il fumo è un ricordo, la salute è la tua realtà.",
        "Ogni giorno è una nuova opportunità per rafforzare la tua scelta.",
        "Sei il custode della tua fiamma interiore.",
        "La libertà di respirare è il lusso più grande.",
        "Non lasciare che il passato oscuri il tuo luminoso futuro.",
        "La tua forza è silenziosa ma inarrestabile.",
        "Ogni scelta conta. Oggi hai scelto bene.",
        "Sei un vincitore, anche quando non sembra.",
        "La costanza è la chiave che apre tutte le porte.",
        "Goditi la sensazione di leggerezza che stai provando.",
        "La tua journey è unica e preziosa.",
        "Ogni ostacolo superato ti rende invincibile.",
        "La pace che cerchi è dentro di te, libera dal fumo.",
        "Sei l'eroe della tua storia di guarigione.",
        "La determinazione non conosce limiti.",
        "Oggi celebriamo la tua forza e la tua resilienza.",
        "Il cambiamento è l'unica costante: abbraccialo.",
        "Sei sulla strada giusta, continua a camminare.",
        "La tua salute fiorisce grazie alle tue cure.",
        "Non mollare: il traguardo ti aspetta a braccia aperte.",
        "Ogni giorno è un miracolo di volontà.",
        "Sei libero di essere la versione migliore di te.",
        "La tua lotta di oggi è la vittoria di domani.",
        "Respira fiducia, espira dubbi.",
        "Sei padrone del tuo tempo e della tua salute.",
        "La gioia di vivere senza catene è indescrivibile.",
        "Ogni passo avanti è un trionfo.",
        "Sei un faro di speranza per te stesso.",
        "La tua dedizione sta portando frutti dolci.",
        "Non dimenticare quanto vali e quanto puoi.",
        "Oggi è un giorno perfetto per essere liberi.",
        "La tua forza ispira il mondo intorno a te.",
        "Sei artefice del tuo benessere.",
        "La libertà è una conquista quotidiana.",
        "Ogni respiro è un canto di vittoria.",
        "Sei più forte di qualsiasi tentazione.",
        "La tua journey è un capolavoro in divenire.",
        "Non fermarti: la meta è vicina.",
        "Sei il campione della tua vita.",
        "La salute è il premio per la tua costanza.",
        "Ogni giorno è un regalo da aprire con gratitudine.",
        "Sei libero, sei forte, sei pronto.",
        "La tua volontà sposta le montagne.",
        "Oggi scegli la gioia di vivere sano.",
        "Sei un esempio di coraggio.",
        "La tua trasformazione è meravigliosa.",
        "Non guardare indietro: il futuro è luminoso.",
        "Sei il costruttore del tuo destino.",
        "La libertà è il tuo stato naturale.",
        "Ogni giorno senza fumo è un successo.",
        "Sei imbattibile quando credi in te.",
        "La tua salute è la tua ricchezza.",
        "Oggi è il giorno per volare alto.",
        "Sei la prova che si può cambiare.",
        "La tua forza è la tua guida.",
        "Ogni scelta ti avvicina alla vetta.",
        "Sei libero dalle catene del passato.",
        "La tua vita è nelle tue mani.",
        "Ogni respiro è un inno alla vita.",
        "Sei un guerriero di luce.",
        "La tua determinazione è incrollabile.",
        "Oggi è un altro giorno di vittoria.",
        "Sei il regista del tuo cambiamento.",
        "La tua salute riflette la tua forza.",
        "Non arrenderti mai alla vecchia abitudine.",
        "Sei destinato a grandi cose.",
        "La libertà è il tuo diritto di nascita.",
        "Ogni giorno è una nuova alba.",
        "Sei il motore del tuo successo.",
        "La tua journey è esemplare.",
        "Ogni passo è una conquista.",
        "Sei libero di sognare in grande.",
        "La tua forza interiore è infinita.",
        "Oggi scegli di brillare.",
        "Sei un modello di resilienza.",
        "La tua salute è il tuo tempio.",
        "Non dimenticare spazio ai dubbi.",
        "Sei il protagonista assoluto.",
        "La libertà è il tuo orizzonte.",
        "Ogni giorno è un trionfo di volontà.",
        "Sei capace di superare tutto.",
        "La tua vita è un dono prezioso.",
        "Oggi è il giorno per essere felici.",
        "Sei la definizione di forza.",
        "La tua trasformazione è ispiratrice.",
        "Non dimenticare il tuo potere.",
        "Sei l'architetto della tua gioia.",
        "La libertà è il tuo orizzonte.",
        "Ogni respiro è libertà pura.",
        "Sei un vincitore nato.",
        "La tua salute è la tua priorità.",
        "Oggi è un giorno speciale.",
        "Sei la prova vivente del cambiamento.",
        "La tua forza è contagiosa.",
        "Ogni scelta è un passo verso la luce.",
        "Sei libero di essere felice.",
        "La tua journey è unica.",
        "Ogni giorno è una benedizione.",
        "Sei il custode della tua pace.",
        "La tua determinazione è ammirevole.",
        "Non mollare la presa sulla tua libertà.",
        "Sei un esempio di virtù.",
        "La libertà è il tuo stato d'animo.",
        "Ogni giorno è un'opportunità d'oro.",
        "Sei forte oltre ogni misura.",
        "La tua salute è il tuo orgoglio.",
        "Oggi è il giorno per vincere.",
        "Sei la luce nella tua vita.",
        "La tua forza non ha confini.",
        "Ogni respiro è un successo.",
        "Sei libero di vivere appieno.",
        "La tua volontà è la tua spada.",
        "Oggi scegli la grandezza.",
        "Sei un eroe quotidiano.",
        "La tua salute è il tuo scudo.",
        "Non dimenticare mai il tuo valore.",
        "Sei il maestro del tuo destino.",
        "La libertà è la tua natura.",
        "Ogni giorno è un capolavoro.",
        "Sei invincibile con la fede.",
        "La tua journey è gloriosa.",
        "Ogni passo è magico.",
        "Sei libero di amare la vita.",
        "La tua forza è leggendaria.",
        "Oggi è il giorno per osare.",
        "Sei un campione di vita.",
        "La tua salute è la tua gloria.",
        "Non arrenderti al buio.",
        "Sei la stella del tuo cielo.",
        "La libertà è il tuo respiro.",
        "Ogni giorno è un miracolo.",
        "Sei il re/regina del tuo regno.",
        "La tua determinazione è sacra.",
        "Ogni scelta è potente.",
        "Sei libero di essere te.",
        "La tua forza è divina.",
        "Oggi è il giorno per splendere."
    ];

    $quoteIndex = ($dayOfYear - 1) % count($dailyQuotes);
    $dailyMessage = $dailyQuotes[$quoteIndex];
    
    $benefits = [
        ["time" => 0.33, "text" => "20 min: Battito e pressione normali."],
        ["time" => 8, "text" => "8 ore: Ossigeno normale, CO dimezzato."],
        ["time" => 12, "text" => "12 ore: Monossido di carbonio eliminato dal sangue."],
        ["time" => 24, "text" => "1 giorno: Inizia il processo di pulizia polmonare."],
        ["time" => 48, "text" => "2 giorni: Nicotina eliminata. Gusto e olfatto migliorano."],
        ["time" => 72, "text" => "3 giorni: Respirare diventa più facile."],
        ["time" => 96, "text" => "4 giorni: I bronchi iniziano a rilassarsi."],
        ["time" => 120, "text" => "5 giorni: Maggiore energia fisica."],
        ["time" => 144, "text" => "6 giorni: La tosse del fumatore può aumentare (segno di pulizia)."],
        ["time" => 168, "text" => "7 giorni: Il senso di fame nervosa inizia a calare."],
        ["time" => 192, "text" => "8 giorni: Migliora la circolazione alle estremità."],
        ["time" => 216, "text" => "9 giorni: Diminuisce il craving (voglia improvvisa)."],
        ["time" => 240, "text" => "10 giorni: L'alito diventa più fresco."],
        ["time" => 264, "text" => "11 giorni: I polmoni espellono più muco."],
        ["time" => 288, "text" => "12 giorni: La pelle appare meno grigia."],
        ["time" => 312, "text" => "13 giorni: Le ciglia bronchiali ricominciano a muoversi."],
        ["time" => 336, "text" => "14 giorni (2 sett): La circolazione migliora sensibilmente."],
        ["time" => 360, "text" => "15 giorni: Camminare richiede meno sforzo."],
        ["time" => 384, "text" => "16 giorni: Migliora la concentrazione."],
        ["time" => 408, "text" => "17 giorni: Il sistema immunitario si rafforza."],
        ["time" => 432, "text" => "18 giorni: Diminuisce l'irritabilità."],
        ["time" => 456, "text" => "19 giorni: I denti iniziano a sbiancarsi naturalmente."],
        ["time" => 480, "text" => "20 giorni: La voce diventa più chiara."],
        ["time" => 504, "text" => "21 giorni: Si consolida la nuova abitudine."],
        ["time" => 528, "text" => "22 giorni: Migliora la qualità del sonno."],
        ["time" => 552, "text" => "23 giorni: Aumenta la resistenza allo stress."],
        ["time" => 576, "text" => "24 giorni: Polmoni più liberi dalle impurità."],
        ["time" => 600, "text" => "25 giorni: Riduzione del rischio di infezioni."],
        ["time" => 624, "text" => "26 giorni: Migliore ossigenazione dei tessuti."],
        ["time" => 648, "text" => "27 giorni: Diminuisce il battito cardiaco a riposo."],
        ["time" => 672, "text" => "28 giorni (4 sett): La funzione polmonare aumenta fino al 30%."],
        ["time" => 696, "text" => "29 giorni: Pelle più luminosa ed elastica."],
        ["time" => 720, "text" => "30 giorni (1 mese): Traguardo del primo mese! Rischio infarto inizia a scendere."],
        ["time" => 792, "text" => "5 settimane: Tosse e affanno diminuiscono drasticamente."],
        ["time" => 864, "text" => "6 settimane: I livelli di energia sono stabili."],
        ["time" => 936, "text" => "7 settimane: Migliora la capacità sportiva."],
        ["time" => 1008, "text" => "8 settimane (2 mesi): Il cuore lavora con meno sforzo."],
        ["time" => 1080, "text" => "9 settimane: La circolazione è quasi pari a quella di un non fumatore."],
        ["time" => 1152, "text" => "10 settimane: Diminuisce il rischio di malattie gengivali."],
        ["time" => 1224, "text" => "11 settimane: Sistema immunitario più reattivo."],
        ["time" => 1296, "text" => "12 settimane (3 mesi): La crescita polmonare migliora ulteriormente."],
        ["time" => 1368, "text" => "13 settimane: Ridotti i sintomi dell'astinenza psicologica."],
        ["time" => 1440, "text" => "14 settimane: Pelle visibilmente più giovane."],
        ["time" => 1512, "text" => "15 settimane: Resistenza alla fatica aumentata."],
        ["time" => 1584, "text" => "16 settimane (4 mesi): Benefici cardiovascolari consolidati."],
        ["time" => 1656, "text" => "5 mesi: I polmoni continuano a guarire internamente."],
        ["time" => 1728, "text" => "6 mesi: Tosse cronica sparita nella maggior parte dei casi."],
        ["time" => 1800, "text" => "7 mesi: Capacità respiratoria ottimale."],
        ["time" => 1872, "text" => "8 mesi: Energia costante durante tutta la giornata."],
        ["time" => 1944, "text" => "9 mesi: Minor rischio di complicanze post-operatorie."],
        ["time" => 2016, "text" => "10 mesi: Denti e gengive molto più sani."],
        ["time" => 2088, "text" => "11 mesi: Soddisfazione personale per il traguardo."],
        ["time" => 2160, "text" => "1 ANNO: Rischio di malattie coronariche DIMEZZATO rispetto a un fumatore."],
        ["time" => 2592, "text" => "1 anno e mezzo: I polmoni hanno recuperato gran parte della funzionalità."],
        ["time" => 3024, "text" => "2 ANNI: Il rischio di infarto si avvicina a quello di chi non ha mai fumato."],
        ["time" => 3456, "text" => "2 anni e mezzo: Ulteriore riduzione del rischio tumorale."],
        ["time" => 3888, "text" => "3 ANNI: La salute cardiovascolare è pari a quella di un non fumatore."],
        ["time" => 4320, "text" => "3 anni e mezzo: Benessere generale consolidato."],
        ["time" => 4752, "text" => "4 ANNI: Riduzione significativa del rischio di ictus."],
        ["time" => 5184, "text" => "4 anni e mezzo: Aspettativa di vita allineata ai non fumatori."],
        ["time" => 5616, "text" => "5 ANNI: Rischio di cancro alla bocca, gola e vescica dimezzato."],
        ["time" => 7884, "text" => "7 ANNI: Rischio di cancro ai polmoni ridotto del 50%."],
        ["time" => 10512, "text" => "10 ANNI: Rischio di morte per cancro ai polmoni pari a un non fumatore."],
        ["time" => 13140, "text" => "15 ANNI: Rischio di malattie cardiache identico a chi non ha mai fumato."]
    ];

} catch (PDOException $e) {
    die("Errore Database: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<base href="./">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
<title>SmokeFree Tracker</title>
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#4CAF50">
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f4f9; margin: 0; padding: 20px; color: #333; padding-bottom: 50px;}
    .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .logout-btn { text-decoration: none; color: #d32f2f; font-weight: bold; font-size: 14px; border: 1px solid #d32f2f; padding: 5px 10px; border-radius: 4px; }
    .card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); text-align: center; }
    .stat-number { font-size: 2.5em; font-weight: 800; color: #4CAF50; margin: 10px 0; line-height: 1; }
    .stat-label { font-size: 0.85em; color: #666; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
    .money-box { background: #e8f5e9; padding: 15px; border-radius: 8px; margin-top: 15px; }
    .money-val { color: #2e7d32; font-weight: bold; font-size: 1.2em; }
    .timeline { text-align: left; }
    .milestone { padding: 15px; border-left: 4px solid #ddd; margin-bottom: 10px; background: #fafafa; border-radius: 0 4px 4px 0; font-size: 14px; }
    .milestone.active { border-left-color: #4CAF50; background: #e8f5e9; font-weight: 600; color: #1b5e20; }
    .milestone.locked { opacity: 0.6; filter: grayscale(0.8); }
    
    form { display: flex; flex-direction: column; gap: 12px; text-align: left; }
    label { font-weight: 600; font-size: 14px; color: #444; }
    input { padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 16px; width: 100%; box-sizing: border-box; }
    button.save-btn { background: #4CAF50; color: white; border: none; padding: 15px; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 10px; }
    button.reset-btn { background: #ffebee; color: #d32f2f; border: 1px solid #ef9a9a; padding: 10px; border-radius: 6px; font-size: 14px; cursor: pointer; margin-top: 20px; width: 100%; }
    
    h1 { color: #4CAF50; text-align: center; margin-bottom: 5px; }
    .welcome { text-align: center; color: #666; font-size: 14px; margin-bottom: 20px; }

    /* STILI PER IL MESSAGGIO GIORNALIERO CON SFONDO TRAMONTO */
    .daily-quote-card {
        /* Immagine di sfondo + Sfumatura */
        /* La sfumatura parte trasparente in alto e diventa bianca al 50% verso il basso */
        background: 
            linear-gradient(to bottom, rgba(255,255,255,0) 0%, rgba(255,255,255,0.95) 77%, rgba(255,255,255,1) 100%),
            url('icons/sunset-over-the-forest.jpg');
        
        background-size: cover;
        background-position: center top;
        background-repeat: no-repeat;
        
        border-radius: 12px;
        padding: 30px 25px;
        margin-bottom: 25px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        text-align: center;
        position: relative;
        overflow: hidden;
        border: 1px solid #eee;
        
        /* Animazione Alba/Nebbia */
        opacity: 0;
        transform: translateY(20px);
        filter: blur(5px);
        animation: dawnReveal 2.5s ease-out forwards;
    }

    .daily-quote-text {
        font-size: 1.15em;
        font-style: italic;
        color: #2c3e50; /* Colore scuro per contrasto sulla sfumatura bianca */
        line-height: 1.6;
        font-weight: 500;
        position: relative;
        z-index: 2;
    }

    .daily-quote-date {
        display: block;
        margin-top: 15px;
        font-size: 0.8em;
        color: #7f8c8d;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        font-weight: 700;
        position: relative;
        z-index: 2;
    }

    @keyframes dawnReveal {
        0% {
            opacity: 0;
            transform: translateY(30px);
            filter: blur(8px);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
            filter: blur(0);
        }
    }
</style>
</head>
<body>

<div class="header">
    <span style="font-weight:bold; color:#4CAF50;">
        <img src="icons/icon-192.png" style="width: 30px; height: auto; margin-bottom: 3px; vertical-align: middle;" alt="Icon" /> 
        SmokeFree
    </span>
    <a href="login.php?logout=1" class="logout-btn">Esci</a>
</div>

<h1>Bentornato, <?php echo htmlspecialchars($username); ?>!</h1>
<p class="welcome">Monitora i tuoi progressi verso una vita senza fumo.</p>

<?php if (!$data): ?>
<!-- FORM INIZIALE SE NON CI SONO DATI -->
<div class="card">
    <h3>Inizia il tuo viaggio</h3>
    <p style="font-size:13px; color:#666; margin-bottom:15px;">Inserisci i dati per calcolare i tuoi progressi.</p>
    <form method="post">
        <label>Data e ora dell'ultima sigaretta:</label>
        <input type="datetime-local" name="start_date" required>
        <label>Costo medio di un pacchetto (€):</label>
        <input type="number" step="0.01" name="pack_cost" placeholder="Es. 6.00" required>
        <label>Quante sigarette fumavi al giorno?</label>
        <input type="number" name="cigs_per_day" placeholder="Es. 20" required>
        <button type="submit" class="save-btn">Salva e Inizia</button>
    </form>
</div>

<?php else: ?>
<!-- DASHBOARD PRINCIPALE -->
<div class="card">
    <div class="stat-label">📅 Giorni senza fumo </div>
    <div class="stat-number"><?php echo $stats['days']; ?></div>
    <div style="font-size:14px; color:#666;">⌚ Ore totali: <?php echo $stats['hours']; ?></div>
    
    <div class="money-box">
        <div class="stat-label">💶 Risparmio Totale</div>
        <div class="money-val">€ <?php echo $stats['money']; ?></div>
        <div style="font-size:12px; color:#666; margin-top:5px;">🚬 Sigarette evitate: <?php echo number_format($stats['cigs_avoided'], 0, ',', '.'); ?></div>
    <div style="font-size:12px; color:#666; margin-top:15px;"><img src="/smokefree/icons/lifebuoy.png" style="width:30px;height:30px;vertical-align:middle;margin-right:3px;" alt="Aiuto"><a href="/smokefree/crisis-killer/" style="text-decoration: none; color: #cc0000;" class="btn-sos"><b>SONO IN CRISI</b></a></div>
    </div>
    <!-- Inserisci questo blocco sotto la statistica delle sigarette evitate -->
<div style="margin-top: 20px; padding: 15px; background: #e8f8f5; border-radius: 8px; border-left: 4px solid #10b981; text-align: left;">
    <div style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 1.5rem;">🛡️</span>
        <div>
            <div style="font-size: 0.9rem; color: #64748b; font-weight: 600;">Crisi d'astinenza superate</div>
            <div style="font-size: 1.0rem; font-weight: 800; color: #0f172a;">
                <?php echo number_format($user['crises_won'] ?? 0); ?> 
                <span style="font-size: 0.9rem; font-weight: 400; color: #10b981;">volte</span>
            </div>
        </div>
    </div>
    <div style="margin-top: 8px; font-size: 0.85rem; color: #64748b;">
        Ogni volta che hai usato il "Salvagente", hai vinto contro la dipendenza.
    </div>
</div>
</div>

<!-- NUOVO BLOCCO: MESSAGGIO GIORNALIERO CON SFONDO TRAMONTO -->
<div class="daily-quote-card">
    <div class="daily-quote-text">
        "<?php echo $dailyMessage; ?>"
    </div>
    <span class="daily-quote-date">Pensiero del Giorno</span>
</div>
<!-- FINE BLOCCO MESSAGGIO -->

<div class="card timeline">
    <h3 style="margin-top:0; color:#333;">⚕️ I tuoi traguardi di salute</h3>
    <?php 
    $current_hours = $stats['hours'];
    foreach($benefits as $b): 
        $is_active = ($current_hours >= $b['time']);
        $class = $is_active ? 'active' : 'locked';
    ?>
    <div class="milestone <?php echo $class; ?>">
        <strong><?php echo explode(':', $b['text'])[0]; ?></strong><br>
        <?php echo trim(explode(':', $b['text'])[1]); ?>
        <?php if($is_active): ?>
            <div style="font-size:11px; font-weight:normal; margin-top:4px; color:#2e7d32;">
                ✓ Raggiunto!
            </div>
        <?php else: ?>
            <div style="font-size:11px; font-weight:normal; margin-top:4px; color:#d32f2f;">
                Mancano circa <?php echo round($b['time'] - $current_hours, 1); ?> ore
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<form method="post" onsubmit="return confirm('Sei sicuro di voler cancellare tutti i dati e ricominciare?');">
    <input type="hidden" name="action" value="reset">
    <button type="submit" class="reset-btn">🗑️ Resetta i miei dati</button>
</form>
<?php endif; ?>

<script>
   /* if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(err => console.log('SW Error:', err));
    }*/
</script>
</body>
</html>