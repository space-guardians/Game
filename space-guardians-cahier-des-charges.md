# Space Guardians — Cahier des charges

Jeu de gestion spatiale multijoueur en temps réel (façon OGame/Travian), développé en monolithe **PHP/Symfony** avec **Symfony UX**.

---

## 1. Pitch

Chaque joueur dirige un empire depuis une planète de départ, dans un univers persistant partagé avec les autres joueurs. Il développe son économie (extraction de ressources), sa technologie, sa flotte et ses défenses, puis interagit avec les autres empires : commerce, exploration, colonisation, espionnage, attaques, alliances. Le jeu tourne en continu même quand le joueur n'est pas connecté : la production, les constructions et les déplacements de flotte avancent en temps réel, et le joueur est notifié en direct des événements importants (fin de construction, arrivée de flotte, attaque en approche).

### Piste narrative (optionnelle, à affiner plus tard)
Les "Gardiens" sont d'anciennes civilisations chargées de protéger des sanctuaires stellaires renfermant une ressource rare (le *deutérium primordial*, par ex.). Chaque empire joueur descend d'un de ces ordres, ce qui justifie technologies et vaisseaux "gardiens". Ce fil narratif reste habillage : il ne doit pas bloquer le développement des systèmes de jeu.

---

## 2. Structure de l'univers

### 2.1 Hiérarchie et adressage
- **Univers** → **Galaxie** → **Système** → **Position** (planète). Cet adressage logique est conservé pour l'affichage, les liens directs et le classement (ex. "Galaxie 1 : Système 342 : Position 7").
- L'univers démarre avec **une seule galaxie** ; d'autres galaxies pourront être ajoutées ultérieurement pour étendre l'univers en cours de vie du jeu, sans toucher aux galaxies existantes.

### 2.2 Génération d'une galaxie
- Une galaxie n'a **pas de taille fixe** : elle a une **forme procédurale** (ex. spirale à *n* branches) et son centre est situé aux coordonnées **(0 ; 0)**.
- La forme détermine une fonction de densité, plus forte près du centre (et le long des branches), qui décroît à mesure qu'on s'en éloigne.
- Les systèmes sont générés **en s'éloignant progressivement du centre**, selon cette fonction de densité, avec une **distance minimale garantie** entre deux systèmes (algorithme de placement par rejet ou Poisson-disc sampling, plutôt qu'un tirage purement aléatoire) pour éviter tout chevauchement de planètes et garder des temps de trajet cohérents même dans les zones denses. La génération s'arrête une fois qu'**environ 1000 systèmes solaires** ont été placés — la galaxie s'étend donc naturellement jusqu'à ce quota, sans limite de rayon fixée à l'avance.
- Chaque système contient **entre 3 et 15 planètes**, réparties sur des orbites concentriques autour de son étoile. **La position d'une planète est relative à son système** (coordonnée locale, ex. orbite + angle), et non une coordonnée globale dans la galaxie — seuls les systèmes ont une coordonnée globale (cf. §4.6, qui détaille comment cela structure les trajectoires de flotte).
- Une position dans un système peut contenir : une planète occupée, une planète libre (colonisable), un emplacement vide, ou un champ de débris (suite à un combat).
- Chaque planète a des caractéristiques fixes à sa création : température (influence la production d'énergie solaire et de deutérium) et position dans le système (influence le potentiel de métal/cristal). Il n'y a **pas de limite de niveau ou de nombre de bâtiments** par planète (cf. §4.3) — donc pas de notion de "taille" limitant la construction.

### 2.3 Carte unifiée et zoom
- L'ensemble (galaxies → systèmes → planètes) est consultable sur une **carte unique interactive**, avec agrégation/masquage progressif du contenu selon le niveau de zoom :
  - Vue large (plusieurs galaxies) : silhouettes des galaxies.
  - Vue galaxie : uniquement les **systèmes**, chacun affiché avec des informations agrégées (nombre total de planètes, combien libres, combien occupées) — les planètes elles-mêmes ne sont pas affichées à ce niveau.
  - Vue système (en zoomant sur un système précis) : le détail des planètes qui le composent, avec leurs positions locales.
  - Le détail d'une planète (bâtiments, etc.) reste un écran dédié, pas un niveau de zoom supplémentaire de la carte.
- Cette séparation "vue galaxie = systèmes" / "vue système = planètes" reflète directement le modèle de coordonnées (systèmes en coordonnées globales, planètes en coordonnées locales à leur système) et la structure des trajectoires de flotte (cf. §4.6).

### 2.4 Placement du joueur à l'inscription
- À l'inscription, le joueur choisit une orientation de jeu indicative : **agressif** ou **producteur**.
  - **Agressif** → placement plus proche du **centre** de la galaxie, zone plus dense en joueurs (plus de contacts, plus d'opportunités/risques PvP).
  - **Producteur** → placement plus en **périphérie**, zone moins dense (développement économique plus tranquille).
- Ce choix influence uniquement la position de départ ; ce n'est pas une contrainte de jeu permanente.

### 2.5 Évolution future (facultatif, hors MVP)
- Rotation des systèmes autour du centre de la galaxie, et des planètes autour de leur étoile.
- Impliquerait de recalculer les trajectoires de flotte vers une cible en mouvement : soit par **prédiction en ligne droite** (point de rencontre estimé à partir de la vitesse angulaire de la cible), soit par une **courbe de Bézier** suivant approximativement le mouvement orbital.
- Nécessite un choix d'architecture dédié (calcul analytique en formule fermée vs recalcul périodique par segments). Le MVP reste **statique** (systèmes et planètes fixes) ; cette évolution sera réévaluée une fois le cœur du jeu stabilisé.

---

## 3. Boucle de jeu principale

1. Le joueur consulte l'état de sa/ses planète(s) : ressources produites depuis sa dernière visite, files en cours.
2. Il construit des bâtiments pour augmenter production et capacités.
3. Il lance des recherches technologiques.
4. Il construit vaisseaux et défenses.
5. Il envoie des flottes exécuter des suites d'ordres (transport, colonisation, exploration, espionnage, recyclage, rejoindre une bataille, raid PvE...).
6. Les événements se résolvent à échéance (asynchrone) : combat, arrivée à destination, fin de construction — le joueur est notifié en temps réel, et peut intervenir en renfort sur une bataille en cours.
7. Il gère les aspects sociaux et économiques : messagerie, alliance, marché (quêtes joueur, vente de ressources/planètes), classement.

---

## 4. Systèmes de jeu détaillés

### 4.1 Comptes & Empire
- Un compte utilisateur = un empire.
- Un empire possède une planète mère + des colonies (limitées par le niveau de la techno "Astrophysique", comme dans les OGame-like classiques).
- Attributs empire : nom, score, alliance, planète active courante.

### 4.2 Ressources
- **Métal**, **Cristal**, **Deutérium** : ressources stockables, produites par les mines.
- **Énergie** : ressource non stockable, calculée en temps réel (production centrales − consommation mines), qui plafonne la production si déficitaire.
- Le stockage est limité par le niveau des dépôts respectifs ; au-delà, la production excédentaire est perdue.
- **Calcul de production** : pas de cron qui tourne en continu pour chaque planète. On stocke `derniere_maj_ressources` + niveaux de mines. Le montant courant est calculé à la demande (production_horaire × temps écoulé), et persisté à chaque action qui en a besoin (visite, construction, attaque...) ou via un job périodique léger de consolidation.

### 4.3 Bâtiments
Liste type : mine de métal, mine de cristal, synthétiseur de deutérium, centrale électrique solaire, centrale à fusion, dépôt de métal/cristal/deutérium, usine de robots, nanite factory, chantier spatial, laboratoire de recherche, hangar de la flotte, silo de missiles.

- Coût croissant exponentiel par niveau.
- Temps de construction dépendant du niveau visé et du niveau de l'usine de robots/nanites.
- Une seule file de construction "bâtiment" active par planète (base) — extensible via une techno/item plus tard.
- **Annulation** : une construction en cours peut être annulée à tout moment. Les ressources sont restituées **au prorata du temps restant** (ex. : annulation à 10 % du temps de construction écoulé → remboursement de 90 % du coût), dans la limite de la **capacité de stockage courante** de la planète ayant lancé la construction (l'excédent au-delà de la capacité est perdu, comme pour toute production). Cette règle s'applique aussi à la recherche (cf. §4.4).

### 4.4 Recherche
- Arbre technologique **au niveau de l'empire** (pas par planète) : énergie, informatique, propulsions (combustion / impulsion / hyperespace), armement, bouclier, coque, espionnage, astrophysique.
- Prérequis croisés entre technologies et bâtiments (ex. : chantier spatial niveau 3 requiert énergie niveau 2).
- Une seule file de recherche active par empire, mais elle peut être **lancée depuis n'importe quelle planète** disposant des ressources nécessaires — le niveau de laboratoire pris en compte pour la vitesse de recherche est la **somme des niveaux de tous les laboratoires de recherche de l'empire**, toutes planètes confondues (pas seulement celui de la planète de lancement).
- **Annulation** : une recherche en cours peut être annulée avec remboursement au prorata du temps restant, dans la limite du stockage de la planète de lancement (même règle que les bâtiments, cf. §4.3).
- **Affichage sous forme d'arbre** : les technologies sont représentées comme des nœuds reliés par leurs prérequis (vue "skill tree"), permettant de distinguer en un coup d'œil ce qui est acquis, disponible immédiatement, ou encore verrouillé.

### 4.5 Vaisseaux & flotte
- **Civils** : transporteur léger, transporteur lourd, colonisateur, recycleur, sonde d'espionnage.
- **Militaires** : chasseur léger, chasseur lourd, croiseur, vaisseau de bataille, destroyer, vaisseau "Gardien" (unité capitale de fin de jeu).
- Caractéristiques : attaque, bouclier, structure (coque), vitesse, capacité de cargo, consommation de carburant.
- Chaque type de vaisseau appartient à une **classe** (intercepteur, bombardier, croiseur, support, capital...), qui détermine ses bonus/malus face aux autres classes en combat (cf. §4.7) — un même type militaire est donc rattaché à exactement une classe, définie depuis le back-office.
- Construits dans le chantier spatial, en file (parallélisable en partie selon niveau de chantier).

### 4.6 Déplacements de flotte

Une flotte ne suit pas une "mission" figée : elle exécute une **suite d'ordres**, chacun de la forme *"se déplacer vers une position, puis effectuer une action"*. Les actions disponibles sont :

| Action | Effet à l'arrivée |
|---|---|
| **Transport de ressources** | Décharge des ressources sur place. Doit cibler une **planète**. |
| **Espionnage** | Récolte des informations sur place. Peut cibler une planète, ou une coordonnée précise si une flotte s'y trouve à l'arrivée. |
| **Recyclage** | Récupère un champ de débris présent sur place. |
| **Exploration** | Déclenche la résolution d'exploration (cf. §4.6.4). Ne peut **pas** cibler de planète. |
| **Rejoindre une bataille** | Attaque toutes les flottes hostiles présentes sur place ; n'attaque jamais les alliés (cf. §4.10.1). |
| **Ravitaillement** | Livre du carburant à une flotte présente sur place, si elle en a besoin. |
| **Stationner** | Reste sur place, sans action supplémentaire — sert aussi bien de renfort défensif (à une planète ou un système) que de simple point de ralliement ou de retour. |
| **Coloniser** | Colonise une planète présente sur place ; consomme le vaisseau colonisateur. |

- **Enchaînement d'ordres** : une flotte peut exécuter une **suite** de ces actions les unes après les autres (ex. : Transport en planète A, puis Recyclage du champ de débris voisin, puis Stationner pour rentrer). Le carnet d'ordres est donc le modèle de gestion par défaut d'une flotte, pas une exception de confort.
- **Exception — Espionnage** : une flotte effectuant cette action doit être composée **uniquement** de la sonde d'espionnage (aucun autre vaisseau), et seule une sonde d'espionnage peut la réaliser. C'est la seule action soumise à une contrainte de composition.
- **Retour au point de départ** : il n'y a pas de retour automatique implicite. Pour rentrer, il faut ajouter explicitement un ordre **"Stationner"** ciblant les coordonnées de départ à la fin de la suite d'ordres. L'interface **suggère** cet ordre par défaut à la création d'une mission (pré-rempli), mais il reste modifiable ou supprimable par le joueur.

#### 4.6.1 Trajectoire et hiérarchie des déplacements
- Les planètes ont des coordonnées **locales** à leur système ; seuls les systèmes ont des coordonnées **globales** dans la galaxie (cf. §2.2). Un trajet entre deux planètes de systèmes différents se décompose donc en (jusqu'à) trois segments :
  1. **Sortie du système d'origine** : de la position locale de la planète de départ jusqu'à la lisière du système.
  2. **Transit interstellaire** : de la position globale du système d'origine à celle du système cible (segment qui domine la durée totale du trajet).
  3. **Approche locale** : de la lisière du système cible jusqu'à la position locale de la planète visée (si la destination est une planète).
  - Si origine et destination sont dans le même système, seul un trajet local s'applique (pas de segment interstellaire).
- Une flotte peut cibler : une planète précise, un **système entier** (elle se stationne alors au niveau du système, cf. §4.6.2), une position précise à l'intérieur d'un système sans viser de planète, ou une position dans l'espace intergalactique en dehors de tout système.
- Temps de trajet = fonction de la distance de chaque segment, de la vitesse du vaisseau le plus lent de la flotte, du % de vitesse choisi par le joueur, et des technologies de propulsion.

#### 4.6.2 Stationnement au niveau d'un système
- Une flotte peut être stationnée directement au niveau d'un **système** (et non d'une planète précise) : elle intercepte alors **toutes** les flottes hostiles arrivant dans ce système, protégeant ainsi l'ensemble des planètes qu'il contient.
- Les **défenses planétaires** (§4.8) ne participent pas à cette interception au niveau système : elles ne défendent que leur propre planète, si une flotte hostile parvient jusqu'à elle (c'est-à-dire si aucune flotte stationnée au niveau du système ne l'a interceptée avant).
- **Une flotte stationnée ne peut jamais être prise par surprise** : elle fait toujours face à l'attaquant, qu'elle soit stationnée à une planète, à un système, ou à toute autre position (cf. §4.7).

#### 4.6.3 Carburant
- La consommation de carburant d'un trajet dépend de la **distance**, du **type de propulsion** (technologie), et de la **consommation propre à chaque type de vaisseau** (ex. un croiseur consomme plus qu'un chasseur), agrégée sur l'ensemble de la flotte.
- **Panne de carburant** : si une flotte manque de deutérium en cours de trajet, elle est **immobilisée sur place**, à la position calculée au moment où le carburant s'épuise. Elle reste bloquée là jusqu'à ravitaillement.
- Chaque vaisseau a une **capacité de réservoir**. Le joueur peut charger **plus de carburant que le strict nécessaire** pour le trajet prévu (dans la limite de cette capacité), afin que la flotte puisse enchaîner un autre déplacement une fois arrivée à destination, sans attendre un ravitaillement.
- **Ravitaillement** : une autre flotte — du même joueur ou d'un **allié** — peut être envoyée en action "Ravitaillement" vers la position exacte d'une flotte immobilisée pour lui livrer du deutérium.

#### 4.6.4 Exploration : quêtes et événements
- L'action d'exploration ne se limite pas à un tirage aléatoire de butin : elle a une **probabilité d'apparition** de déclencher une quête ou un événement narratif, et peut être **conditionnée** par les technologies du joueur, les types de vaisseaux envoyés, ou les ressources transportées par la flotte.
- Les événements sont définis depuis le back-office (cf. §5.6) sous forme de gabarits (`QuestTemplate`) : probabilité d'apparition, conditions de déclenchement, texte, choix proposés au joueur, récompenses/risques par issue.
- **Quêtes chaînées** : une quête peut, selon son issue, en déclencher une suivante. Exemple : *quête 1* — un signal de détresse est repéré, la flotte part enquêter ; il s'avère qu'il s'agit d'une flotte endommagée en route pour porter secours à une autre flotte, à des coordonnées données ; *quête 2* — il faut s'y rendre avec certains types de vaisseaux ou certaines ressources pour l'assister/la défendre, avant une échéance donnée.
- Une instance d'événement est créée à l'arrivée de la flotte en exploration ; selon le type, elle se résout automatiquement (tirage pondéré) ou attend un choix du joueur avant résolution et notification du dénouement.

### 4.7 Combat
- **Pas d'explosion aléatoire** : la destruction d'un vaisseau est entièrement déterministe une fois les dégâts calculés (structure ramenée à 0 = détruit). Le seul aléa porte sur la précision (chance de toucher).
- **Classes de vaisseaux et matchups** : chaque vaisseau appartient à une classe (ex. intercepteur, bombardier, croiseur, support, capital...). Une matrice de bonus/malus définit, pour chaque paire de classes, un multiplicateur de dégâts (logique "pierre-feuille-ciseaux", ex. intercepteurs efficaces contre bombardiers mais pénalisés face aux croiseurs). Matrice éditable depuis le back-office.
- **Formation** : à la création/l'envoi d'une flotte, le joueur répartit ses vaisseaux sur une grille de formation (ex. lignes avant/milieu/arrière × colonnes gauche/centre/droite). La position dans la formation détermine la probabilité qu'un vaisseau soit engagé en premier selon l'angle d'attaque subi.
- **Une flotte stationnée ne peut jamais être prise par surprise : elle fait toujours face à l'attaquant** (cf. §4.6.2). Il n'y a donc pas de bonus "attaque par l'arrière" contre une cible à l'arrêt (planète, système ou toute autre position) — c'est toujours un engagement frontal, ligne avant de la formation défensive engagée en premier.
- **Angle d'attaque entre flottes convergentes** : la nuance face/arrière/flanc ne s'applique qu'entre plusieurs flottes **simultanément en mouvement** vers un même point de rencontre (ex. plusieurs flottes rejoignant une bataille depuis des directions différentes) — le moteur compare alors leurs vecteurs d'approche respectifs pour déterminer qui engage en premier quelle ligne de formation adverse.
- Résolution par rounds (ex. 6 rounds), dégâts modulés par : classe (matrice), position de formation touchée, bouclier, technologies d'armement/bouclier/coque.
- Génération d'un rapport de combat détaillé (dégâts par unité, pertes, angle d'attaque, formation), envoyé aux deux parties (et à leurs alliances si le paramètre est activé).
- Génération d'un champ de débris (métal/cristal) récupérable par recycleurs.
- Pillage : l'attaquant repart avec des ressources dans la limite de sa capacité de cargo restante.
- **Bataille à plusieurs flottes** : une attaque n'est pas figée dès qu'une flotte hostile arrive. Dès qu'elle est détectée (cf. §4.12), elle devient une **bataille programmée**, avec un lieu et une heure de résolution. Jusqu'à cette heure, n'importe quel joueur peut envoyer une flotte stationnée en action "Rejoindre une bataille" (destination = position de la bataille) : cette action attaque automatiquement toutes les flottes hostiles présentes et n'attaque jamais les alliés (cf. §4.10.1) — il n'y a pas de choix manuel de camp, l'engagement se détermine pour chaque paire de flottes selon leur statut allié/ennemi au moment de la résolution. Une flotte déjà en vol vers une autre destination ne peut pas être réaffectée à la bataille — seule une flotte stationnée peut être lancée en renfort.

*Remarque : les formules précises (matrice de classes, poids de chaque angle d'attaque, courbe de précision, et la résolution d'un affrontement à plus de deux empires simultanés) demandent une passe de balancing dédiée avant l'implémentation finale — voir §7.*

### 4.8 Défenses planétaires
- Rampes de lancement de missiles, lasers légers/lourds, canons de gauss, boucliers planétaires, etc.
- Ne consomment pas de flotte, contribuent uniquement à la défense de la planète.

### 4.9 Espionnage
- Envoi de sondes d'espionnage pour obtenir un rapport (ressources, bâtiments, flotte, recherche, défenses).
- Niveau de détail et risque de détection dépendent des niveaux de technologie d'espionnage respectifs de l'attaquant et de la cible.

### 4.10 Alliances
- Création/gestion d'alliance, rôles (fondateur, diplomate, membre), chat d'alliance (onglet du chat en ligne, cf. §4.12.1), classement d'alliance.
- Entraide : transfert de ressources, envoi de flottes en renfort (action "Stationner", cf. §4.6) sur une planète ou un système alliés.
- **Planète(s) d'alliance** : une alliance peut faire construire une (ou plusieurs, cf. §7) planète(s) artificielle(s), propriété collective de l'alliance plutôt que d'un joueur.
  - Bâtiments **uniques**, non disponibles sur les planètes classiques :
    - **Scanner d'alliance** : détecte les flottes présentes ou en déplacement dans un rayon donné autour de la planète, avec système d'alerte (notification temps réel aux membres).
    - **Porte de téléportation** : une flotte entrant dans une porte ressort par une autre porte reliée après un délai défini. Pendant le transit, la flotte est **intouchable** (ne peut ni être attaquée, ni attaquer) mais elle **ne peut pas rebrousser chemin** avant la sortie.
  - Une planète d'alliance peut être **attaquée, pillée voire détruite** par des empires ennemis, avec ses propres défenses à développer.

#### 4.10.1 Alliés, ennemis et pactes de paix
- **Membres d'une même alliance** : automatiquement considérés comme alliés entre eux — ils ne peuvent pas s'attaquer.
- **Pacte d'alliance bilatéral** : deux joueurs (d'alliances différentes ou sans alliance) peuvent conclure un pacte de non-agression, avec une **durée négociée entre eux** au moment de sa conclusion (durée maximale : **1 an**). Pendant sa durée, les deux parties ne peuvent pas s'attaquer.
- **Un pacte, une fois établi, ne peut pas être rompu par anticipation** : il court jusqu'à son terme, sans résiliation possible, unilatérale ou mutuelle.
- Ce statut allié/ennemi entre deux empires est vérifié partout où une action hostile est possible (action "Rejoindre une bataille", pillage, etc., cf. §4.6 et §4.7) : le jeu empêche toute action agressive entre deux empires alliés (même alliance ou pacte actif).

### 4.11 Classement & scores
- Score individuel = somme pondérée des points de construction, recherche, flotte, exploration/colonisation.
- Classements disponibles :
  - **Global** (score cumulé toutes catégories confondues) ;
  - **Par catégorie** : économie/bâtiments, recherche, flotte/militaire, exploration ;
  - Vues individuelle et par alliance pour chacun de ces classements.

### 4.12 Communication : chat, messagerie & notifications
Trois outils distincts, qui ne se recouvrent pas :

| Outil | Persistance | Usage |
|---|---|---|
| **Chat en ligne** | Non persistant | Discussion instantanée entre joueurs connectés |
| **Messagerie** | Persistante | Échanges asynchrones joueur ↔ joueur(s) et messages système → joueur(s) |
| **Notifications** | Persistante (historique) | Alertes courtes sur un événement de jeu, avec lien vers l'écran concerné |

#### 4.12.1 Chat en ligne
- Widget de chat accessible depuis toutes les pages du jeu, organisé en **onglets** :
  - **Public** : canal unique ouvert à tous les joueurs connectés de l'univers.
  - **Alliance** : canal réservé aux membres de l'alliance du joueur (onglet absent si le joueur n'a pas d'alliance).
  - **Privé** : discussion en tête-à-tête avec un autre joueur ; un onglet par interlocuteur.
  - **Groupe** : salon créé par un joueur, qui y **ajoute** d'autres joueurs ; un onglet par groupe. Le créateur peut retirer des participants ; chacun peut quitter le groupe.
- **Non persistant** : les messages ne sont pas enregistrés en base de données. Un joueur ne reçoit que les messages émis pendant qu'il est connecté. Un court tampon en mémoire (quelques dizaines de messages par canal, durée de vie limitée) permet seulement de retrouver le fil récent après un changement de page ou une reconnexion brève, et de joindre un extrait à un signalement.
- Un groupe de chat est lui aussi éphémère : il disparaît après une période d'inactivité ou quand son dernier participant le quitte.
- **Présence** : indicateur des joueurs en ligne (membres d'alliance en ligne, interlocuteur privé connecté ou non).
- Un message de chat ne peut être envoyé qu'à un joueur connecté. Pour joindre un joueur hors ligne, on passe par la messagerie (§4.12.2).
- Sécurité : longueur maximale des messages, rate limiting, liste de joueurs ignorés (§4.12.4), signalement d'un message à la modération (§4.13).

#### 4.12.2 Messagerie
- Messagerie **persistante**, organisée en **conversations** :
  - **Joueur ↔ joueur** : conversation à deux.
  - **Conversation de groupe** : un joueur crée une conversation avec plusieurs destinataires et peut y **ajouter des participants** par la suite ; un participant peut quitter la conversation.
  - **Système → joueur(s)** : messages émis par le jeu (pas de réponse possible). Ils peuvent viser un joueur, une liste de joueurs, une alliance ou tous les joueurs (annonce depuis le back-office).
- Rapports automatiques (combat, espionnage, transport, expédition) : délivrés comme des messages système, classés par catégorie.
- Pour chaque participant : statut lu / non lu, archivage, suppression de son côté uniquement (la conversation reste visible pour les autres).
- Compteur de messages non lus mis à jour en direct.

#### 4.12.3 Notifications
- Notifications système en direct : fin de construction, arrivée de flotte, attaque entrante détectée ("vous êtes attaqué, arrivée dans 12 min"), nouveau message reçu.
- Badge de notifications non lues en direct + historique.
- Diffusion via **Mercure** : mise à jour live des composants (compteurs, files, badges) sans rechargement de page.

#### 4.12.4 Joueurs ignorés
- Un joueur peut en ignorer un autre : il ne reçoit plus ses messages de chat (privé, et masqués dans les canaux public, alliance et groupe), ni ses messages en messagerie. Il ne peut pas non plus être ajouté par lui à un groupe ou à une conversation.

### 4.13 Fair-play / anti-triche
- Mode vacances (production réduite, protection contre les attaques).
- Bouclier de protection pour les nouveaux comptes (durée ou seuil de score).
- Limitation du nombre de comptes par IP/appareil, logs d'activité, rate limiting sur les actions sensibles (envoi de flotte, messages).
- Modération du chat et de la messagerie : signalement d'un message par un joueur, traitement en back-office, sanctions (mise en sourdine temporaire du chat, bannissement).

### 4.14 PvE — Civilisations non-joueuses (NPC)
- Objectif : offrir une alternative au PvP (jouable en solo ou en défense) et un terrain de coopération inter-alliance.
- Plusieurs civilisations NPC peuplent l'univers, avec leurs propres planètes, ressources et flottes, gérées par le moteur de jeu (pas par des joueurs). Deux catégories :
  - **Civilisations mineures (faibles à moyennes)** : planètes avec ressources et flotte de défense/attaque modeste. Elles peuvent lancer des raids ponctuels sur des joueurs à proximité, et sont attaquables/pillables comme cible d'entraînement ou source de revenu, avec un risque calibré pour rester abordable en solo. Leurs raids et combats réutilisent le même moteur de déplacement/combat que les joueurs (cf. §4.6 et §4.7), pour rester cohérent techniquement.
  - **Civilisations "boss" / gardiennes** : véritable défi de fin de jeu, nécessitant la coordination de plusieurs joueurs/alliances.
    - Leur flotte est **recalculée périodiquement** pour rester proportionnée au niveau du serveur — par exemple indexée sur la flotte militaire moyenne des 10 meilleurs joueurs (ou alliances) du classement militaire (cf. §4.11).
    - Elles sont **strictement défensives** : elles ne lancent jamais d'attaque, elles ripostent uniquement si on les attaque. Ce sont des objectifs volontaires ("raid de boss"), pas une menace subie.
    - Leurs planètes offrent des récompenses substantielles (ressources rares, plans de vaisseaux...) justifiant l'effort de coordination.
- Paramétrage entièrement piloté depuis le back-office (cf. §5.6) : composition de flotte, seuil/fréquence de recalcul, fréquence de raid, butin — permet d'ajuster l'équilibrage sans redéploiement.
- **Administration** : les civilisations mineures sont **entièrement administrables** depuis le back-office (création, emplacement dans la galaxie, composition et création de flottes, déclenchement manuel d'ordres d'attaque). Les civilisations "boss", elles, sont surtout **monitorées** depuis le back-office (suivi de leur flotte recalculée, de leur statut, de l'historique des raids subis) plutôt que pilotées au coup par coup, puisque leur comportement est automatique et strictement défensif.

### 4.15 Marché
- Objectif : donner aux joueurs des interactions économiques et sociales au-delà du combat, avec un marché piloté par les joueurs (et, pour les quêtes, par les PNJ également).
- **Marchands** : des PNJ marchands sont disséminés un peu partout dans la galaxie (positions fixes). Ils servent de point de dépôt/retrait neutre pour tous les échanges du marché. **Un marchand ne peut jamais être attaqué**, et sa capacité de stockage est **illimitée**.
- **Vente/achat de ressources** : un joueur poste une offre (quantité, prix) ; les ressources concernées doivent être **transportées jusqu'à un marchand** par le vendeur (mission de transport classique, donc **interceptable en chemin comme n'importe quel transport**), et l'acheteur doit livrer sa contrepartie (ressources ou paiement convenu) au même marchand. **La transaction n'est validée que lorsque les deux parties ont livré leur dû** au marchand. Une fois validée, chacun peut venir récupérer ce qui lui revient — les ressources restent stockées chez le marchand jusqu'à ce que le bénéficiaire vienne les chercher (mission de transport de retour). **Un joueur ne peut retirer que les ressources qui lui sont destinées.**
- **Contrats de quêtes (joueurs ou PNJ)** : une offre publique peut aussi être postée par un PNJ (pas seulement un joueur), par exemple pour des quêtes générées par le système. Type envisagé pour plus tard (cf. ci-dessous) : escorte de flotte.
  - **Quête d'escorte** *(fonctionnalité différée à une implémentation ultérieure, cf. §7)* : récompense versée si la flotte protégée arrive à destination sans perte. Elle se définit comme une suite d'ordres de flotte (position de départ, position d'arrivée, heure de départ, heure d'arrivée), mais **seules la position et l'heure de départ sont connues à l'avance** des autres joueurs — la destination et l'heure d'arrivée restent secrètes jusqu'à l'arrivée, pour limiter les attaques préméditées sur le trajet.
- **Vente de planètes** : un joueur peut mettre en vente une planète, seule ou en échange d'une autre planète (troc).
  - Dès la mise en vente, la planète est **gelée** : elle ne peut plus être pillée et **ne produit plus rien**, jusqu'à ce qu'elle soit achetée.
  - Le vendeur peut, après la vente, coloniser une nouvelle planète pour la remplacer (sous réserve d'avoir un emplacement de colonisation disponible, cf. §4.6).
  - L'acheteur doit disposer d'un **emplacement de colonisation libre** au moment de l'achat pour pouvoir l'acquérir.
  - Un échange direct planète contre planète est également possible (les deux planètes concernées sont gelées le temps de la transaction).

*Remarque : les quêtes d'escorte introduisent une vraie complexité (secret partiel de trajectoire, validation de "sans perte") — elles sont volontairement repoussées à une phase ultérieure de la roadmap plutôt qu'intégrées au MVP du marché (cf. §6, Phase 10).*

---

## 5. Architecture technique

### 5.1 Stack retenue
- **Symfony 7.x** en monolithe.
- **Doctrine ORM** + **PostgreSQL** (préférée à MySQL pour la robustesse transactionnelle sous concurrence).
- **Symfony UX** :
  - **Turbo** (Drive + Frames + Streams) pour la navigation et les mises à jour partielles sans JS custom.
  - **Stimulus** pour les interactions ponctuelles (formulaires, compte à rebours côté client).
  - **Live Components** pour les éléments réactifs (file de construction, sélecteur de flotte à envoyer, formulaires dynamiques).
- **Mercure** (hub officiel Symfony) pour le push temps réel : arrivée de flotte, fin de construction, attaque en approche, messagerie. Combiné avec les Turbo Streams (« Turbo Streams over Mercure »), ça évite d'écrire du WebSocket à la main.
- **Symfony Messenger** + transport asynchrone (Doctrine ou Redis) pour tout traitement différé (fin de construction, arrivée de flotte, résolution de combat).
- **Symfony Scheduler** (ou cron classique) pour les tâches périodiques (maintenance, purge, sauvegardes).
- **Symfony Security** pour l'authentification, **Symfony RateLimiter** pour la protection contre l'abus d'actions.
- **Redis** : cache applicatif, sessions, verrous (Symfony Lock) pour éviter les races sur les ressources en cas d'actions concurrentes, et état éphémère du chat (tampon de messages récents, groupes de chat), cf. §5.7.
- **EasyAdminBundle** pour le back-office (cf. §5.6) : génère l'administration directement à partir des entités Doctrine, sans application séparée.
- **PHPUnit** pour les tests unitaires/fonctionnels, **Symfony Panther** pour les tests bout-en-bout sur les pages critiques.
- **Flux Git** : `develop` (intégration) → `staging` (préproduction) → `main` (production) ; les branches de travail partent de `develop`.

### 5.2 Gestion des échéances (le cœur "temps réel")
Il n'y a pas besoin de 60 fps ici : le vrai sujet est la fiabilité de la planification à échéance (ex. "dans 2h14min") et la notification instantanée au bon moment.

Approche recommandée :
1. Table `scheduled_event` (type, payload JSON, `planet_id`/`fleet_id`, `executed_at`, statut).
2. Un worker Symfony Messenger consomme en continu les événements dont l'échéance est dépassée (poll périodique léger, ou un message différé par événement via un stamp de délai).
3. Le handler résout l'événement (met à jour les entités concernées), puis publie sur Mercure vers les topics pertinents (`/empire/{id}`, `/planet/{id}`) pour rafraîchir l'UI en direct via un fragment Turbo Stream.
4. Un verrou (Symfony Lock) protège chaque planète/flotte pendant le traitement pour éviter les doubles résolutions.

### 5.3 Entités principales (modèle de données)
`User`, `Empire`, `Galaxy`, `GalaxyShapeTemplate`, `System`, `Planet`, `AlliancePlanet`, `Building`, `AllianceBuilding` (scanner, porte de téléportation), `TeleportLink`, `BuildingQueueItem`, `Research`, `ResearchQueueItem`, `ShipType`, `ShipClass`, `ClassMatchup`, `Formation`, `FormationSlot`, `Fleet`, `FleetMovement` (position/heure de départ, destination, statut : en vol / stationnée / immobilisée), `FleetOrder` (carnet d'ordres, ordres suivants programmés), `Battle` (lieu, heure de résolution, statut), `BattleParticipant` (flotte, camp), `CombatReport`, `EspionageReport`, `Alliance`, `AllianceMember`, `AlliancePact` (deux empires, durée max 1 an, non résiliable), `Conversation` (joueur ↔ joueur, groupe, ou système), `ConversationParticipant` (dernier message lu, archivage, suppression de son côté), `Message` (auteur nul pour un message système), `PlayerIgnore`, `MessageReport` (signalement), `Notification`, `ScheduledEvent`, `Debris`, `QuestTemplate`, `ExplorationEventInstance`, `NpcBehaviorProfile`, `Merchant` (PNJ, position fixe, stockage illimité), `MerchantHolding` (ressources déposées par un joueur, en attente de retrait ou de contrepartie), `MarketListing` (vente de ressources, vente/échange de planète), `PlayerQuest` (contrats, dont les quêtes d'escorte différées).

### 5.4 Écrans principaux
- Vue d'ensemble planète (ressources, files en cours, alertes)
- Bâtiments
- Recherche
- Chantier spatial (vaisseaux + défenses)
- Flotte (envoyer une mission, définir une formation, missions en cours, ravitaillement des flottes immobilisées, carnet d'ordres)
- Galaxie (systèmes/planètes voisines, cibles PvE et PvP)
- Alliance (dont gestion de la/des planète(s) d'alliance)
- Marché (annonces, mes contrats en cours, historique)
- Messagerie & rapports
- Chat en ligne (widget présent sur toutes les pages, à onglets)
- Classement (global et par catégorie)
- Paramètres du compte / mode vacances

### 5.5 Génération procédurale & rendu de la carte
- **Génération** : la galaxie (forme, placement des systèmes et planètes) est produite par une commande CLI Symfony dédiée, avec une **seed déterministe** (rejouable à l'identique, utile pour les tests et le débogage). Le gabarit de forme (spirale à *n* branches, paramètres de densité) est défini depuis le back-office pour permettre de générer de nouvelles galaxies sans toucher au code.
- **Rendu de la carte — point d'attention** : la préférence générale du projet est une interface HTML/CSS classique (cartes, listes, boutons), portée par Turbo/Live Components. La carte galactique zoomable (~1000 systèmes, potentiellement plusieurs milliers de planètes, zoom/dézoom fluide avec agrégation dynamique) est le seul écran qui sort de ce cadre : afficher/mettre à jour un tel volume d'éléments comme des fragments HTML classiques serait trop lourd, et le zoom continu ne se prête pas au cycle requête/réponse de Turbo Streams.
  - Recommandation : un rendu **SVG ou Canvas piloté par un contrôleur Stimulus dédié**, alimenté par un endpoint JSON (coordonnées et métadonnées des systèmes/planètes visibles dans le viewport courant). Le reste de l'application (écrans de gestion, formulaires, flotte, alliance...) garde l'approche HTML/CSS + Live Components.
  - Le choix précis de librairie (ex. PixiJS pour du canvas performant, D3.js pour du SVG + zoom/pan, ou une carte type Leaflet détournée avec un CRS personnalisé) reste à trancher lors de l'implémentation de cet écran (cf. §7).
- **Cas différent : l'arbre de recherche** (§4.4) reste, lui, dans le cadre HTML/CSS classique — quelques dizaines de nœuds au maximum, positions calculables côté serveur, pas besoin de zoom continu ni de librairie graphique dédiée (du SVG simple généré en Twig, ou une grille CSS avec des connecteurs, suffit).

### 5.6 Back-office d'administration
- Basé sur **EasyAdminBundle**, généré directement à partir des entités Doctrine.
- Permet de créer/modifier sans redéploiement de code : bâtiments et leurs coûts/effets, technologies et prérequis, types et classes de vaisseaux, matrice de bonus/malus, types de défenses, gabarits de quêtes/événements d'exploration, gabarits de forme de galaxie, civilisations NPC (composition, fréquence de raid/recalcul), paramètres globaux (vitesse d'univers, etc.).
- Accès restreint par rôle Symfony Security (`ROLE_ADMIN`), séparé des comptes joueurs.
- Livré **progressivement** : un écran d'admin est ajouté dès qu'une entité de configuration de jeu est introduite dans une phase (plutôt qu'en bloc à la fin), pour permettre de peupler les données de test au fur et à mesure du développement.

### 5.7 Chat en ligne & messagerie
Le chat et la messagerie réutilisent la stack existante (Mercure, Redis, Messenger) : aucun serveur WebSocket ni service tiers supplémentaire.

**Chat en ligne (non persistant)**
- **Transport : Mercure** (Server-Sent Events). Le navigateur ne publie jamais directement sur le hub : il envoie chaque message par une requête HTTP POST à un contrôleur Symfony, qui vérifie les droits, applique le rate limiting (**Symfony RateLimiter**), la longueur maximale et la liste des joueurs ignorés, puis publie sur le hub. Le hub ne fait que diffuser.
- **Topics et autorisations** :
  - `/chat/public` : canal public, un seul topic partagé (abonnement autorisé pour tout joueur authentifié).
  - `/users/{id}/chat` : topic personnel privé du joueur. Les messages **d'alliance, privés et de groupe** sont envoyés par le serveur sur le topic personnel de chaque destinataire (fan-out).
  - Ce choix limite le JWT d'abonnement (cookie `mercureAuthorization`) à deux topics fixes : il n'a pas besoin d'être régénéré quand un joueur rejoint/quitte une alliance ou un groupe, et un joueur exclu cesse immédiatement de recevoir les messages. Le coût du fan-out reste faible à l'échelle d'une alliance ou d'un groupe.
- **État éphémère dans Redis** : tampon circulaire des derniers messages par canal (taille et durée de vie limitées, ex. 50 messages / 1 h), composition des groupes de chat avec expiration après inactivité. Aucune table Doctrine pour les messages de chat.
- **Présence** : à partir de l'API d'abonnements actifs de Mercure (événements de connexion/déconnexion), ou à défaut d'un battement de cœur périodique stocké dans Redis avec expiration.
- **Front** : un contrôleur **Stimulus** dédié (onglets, `EventSource`, envoi, compteur de messages non lus par onglet). Le widget est marqué `data-turbo-permanent` pour survivre aux navigations Turbo Drive sans couper la connexion ni perdre les onglets ouverts. On n'utilise pas de Live Component ici : le volume et la fréquence des messages ne se prêtent pas à un aller-retour serveur par rendu.

**Messagerie (persistante)**
- **Doctrine + PostgreSQL** : `Conversation`, `ConversationParticipant`, `Message` (auteur nul pour un message système). Index sur (participant, date du dernier message) pour la liste des conversations.
- Écrans en HTML classique (Turbo Frames, Live Components pour la rédaction et l'ajout de participants).
- **Temps réel** : à chaque nouveau message, publication Mercure (Turbo Streams) sur le topic personnel de chaque participant pour mettre à jour la conversation ouverte, la liste des conversations et le compteur de non lus.
- **Messages système** : un service unique (`SystemMessenger`) utilisé par tout le code de jeu (rapports, événements) et par le back-office. Les envois de masse (alliance, tous les joueurs) sont traités en asynchrone par **Symfony Messenger**, par lots, pour ne pas bloquer la requête.

---

## 6. Roadmap de développement (ordonnée)

### Phase 0 — Fondations
- [ ] Initialiser le projet Symfony (skeleton webapp) + Docker Compose (PHP-FPM, PostgreSQL, Redis, Mercure hub, Mailer local)
- [ ] Installer et configurer Symfony UX (Turbo, Stimulus, Live Components) + Asset Mapper
- [ ] Mettre en place le hub Mercure (clés JWT publish/subscribe, config `.env`)
- [ ] CI de base (PHPUnit, PHPStan/Psalm, PHP-CS-Fixer)

### Phase 1 — Génération de l'univers
- [ ] Entités `Galaxy`, `System`, `Planet` avec coordonnées réelles (systèmes en coordonnées globales depuis le centre (0 ; 0), planètes en coordonnées locales à leur système, cf. §2.2)
- [ ] Algorithme de génération procédurale : forme (spirale à *n* branches), fonction de densité, placement des systèmes avec distance minimale garantie (Poisson-disc sampling ou équivalent)
- [ ] Génération des planètes par système (3 à 15, orbites concentriques)
- [ ] Commande CLI de génération d'une galaxie, seed déterministe (rejouable pour les tests)
- [ ] Back-office : gabarits de forme de galaxie (EasyAdmin, cf. §5.6)

### Phase 2 — Comptes & Empire
- [ ] Entité `User` + authentification Symfony Security (inscription, connexion, mot de passe oublié)
- [ ] Entité `Empire` + planète mère assignée à l'inscription, selon le profil choisi (agressif → proche du centre, producteur → périphérie)
- [ ] Page "Vue d'ensemble" de la planète active (statique dans un premier temps)
- [ ] Installer EasyAdminBundle + configurer l'accès `ROLE_ADMIN` (socle du back-office, alimenté phase par phase)

### Phase 3 — Économie (MVP jouable en solo)
- [ ] Modèle de ressources + calcul de production "à la volée" (temps écoulé × taux horaire)
- [ ] Bâtiments : mines, dépôts, centrale électrique + calcul de l'énergie
- [ ] File de construction de bâtiments (via Messenger : planification + résolution asynchrone)
- [ ] Annulation d'une construction en cours, remboursement au prorata du temps restant (plafonné au stockage)
- [ ] Live Component pour afficher la file de construction et le compte à rebours en direct
- [ ] Premier flux Mercure : notification de fin de construction en direct

### Phase 4 — Recherche
- [ ] Arbre technologique + prérequis
- [ ] File de recherche unique par empire, lançable depuis n'importe quelle planète, niveau = somme des laboratoires de l'empire
- [ ] Annulation d'une recherche en cours, remboursement au prorata du temps restant (plafonné au stockage)
- [ ] Écran "Arbre de recherche" (rendu HTML/SVG, positions calculées côté serveur, cf. §5.5)

### Phase 5 — Flotte (sans combat)
- [ ] Chantier spatial : construction de vaisseaux civils et militaires, avec notion de classe
- [ ] Back-office : CRUD types/classes de vaisseaux (EasyAdmin)
- [ ] Hangar / inventaire de flotte par planète
- [ ] Moteur de trajectoire multi-segments (sortie du système d'origine / transit interstellaire / approche locale, cf. §4.6.1) et calcul de la position courante d'une flotte en vol
- [ ] Moteur de **suite d'ordres** (`FleetOrder`) : enchaînement d'actions programmées pour une flotte — c'est le modèle de gestion par défaut (cf. §4.6), pas une fonctionnalité annexe. Premier cas d'usage : action "Transport de ressources" (cible une planète, décharge à l'arrivée, aucun retour automatique)
- [ ] Écran de formation (répartition des vaisseaux sur une grille avant/milieu/arrière × gauche/centre/droite)
- [ ] Carte interactive avec zoom (galaxie → système → planète), rendu SVG/Canvas via Stimulus (cf. §5.5)

### Phase 6 — Colonisation & exploration
- [ ] Action "Coloniser" (cible une planète, consomme le vaisseau colonisateur — pas de retour possible)
- [ ] Action "Stationner" (reste sur place ; c'est l'action à ajouter explicitement en fin de suite d'ordres pour faire revenir une flotte à son point de départ, suggérée par défaut par l'interface — cf. §4.6)
- [ ] Panne de carburant en vol (immobilisation) + action "Ravitaillement" pour livrer du deutérium à une flotte immobilisée (soi-même ou un allié)
- [ ] Action "Exploration" (ne peut pas cibler de planète) avec système de quêtes/événements : probabilité d'apparition, conditions (technologies, vaisseaux envoyés, ressources transportées), et chaînage de quêtes (`QuestTemplate` en back-office)
- [ ] Limite du nombre de colonies selon technologie

### Phase 7 — Combat
- [ ] Matrice de bonus/malus entre classes de vaisseaux (back-office + moteur de calcul)
- [ ] Stationnement au niveau d'un système (interception de toutes les flottes hostiles y arrivant ; les défenses planétaires n'y participent pas, cf. §4.6.2)
- [ ] Règle "jamais de surprise" : une flotte stationnée (planète, système ou autre position) fait toujours face à l'attaquant — engagement frontal systématique, pas de bonus "par l'arrière" contre une cible à l'arrêt
- [ ] Moteur de calcul de l'angle d'attaque entre flottes convergentes en mouvement (face / arrière / flanc, cf. §4.7)
- [ ] Moteur de résolution de combat (rounds, dégâts modulés par classe/formation/angle, bouclier, structure — sans explosion aléatoire)
- [ ] Entité `Battle` : bataille programmée (lieu, heure de résolution, statut)
- [ ] Action "Rejoindre une bataille" : attaque automatiquement toute flotte hostile présente, jamais les alliés — aucun choix manuel de camp, déterminé par le statut allié/ennemi (cf. §4.10.1) ; agrégation des flottes renforçant chaque côté avant résolution
- [ ] Génération de rapport de combat détaillé (dégâts, pertes, angle, formation) + notification temps réel aux deux parties
- [ ] Génération de champs de débris + action "Recyclage"
- [ ] Pillage de ressources selon capacité de cargo restante

### Phase 8 — Défense & espionnage
- [ ] Défenses planétaires (construction, intégration dans le calcul de combat)
- [ ] Sondes d'espionnage + rapport d'espionnage (niveau de détail selon techno)
- [ ] Détection de sonde par la cible (selon techno adverse)

### Phase 9 — Social
- [ ] Messagerie : conversations joueur ↔ joueur et de groupe (ajout de participants), lu/non lu, archivage, compteur de non lus en direct
- [ ] Messagerie : messages système vers un ou plusieurs joueurs (service unique, envois de masse asynchrones, annonces depuis le back-office)
- [ ] Rapports automatiques (combat, espionnage, transport, expédition) délivrés en messages système
- [ ] Chat en ligne : socle (Mercure, topics, Redis, widget Stimulus à onglets) + canal public
- [ ] Chat en ligne : présence des joueurs connectés
- [ ] Chat en ligne : onglets privé, groupe (ajout de participants) et alliance
- [ ] Liste de joueurs ignorés (chat et messagerie)
- [ ] Alliances (création, rôles, entraide en ressources et en renfort militaire)
- [ ] Statut allié/ennemi : appartenance à une même alliance + pactes bilatéraux (`AlliancePact`, durée négociée, max 1 an, non résiliable) — vérifié partout où une action hostile est possible
- [ ] Planète(s) d'alliance : construction, propriété collective
- [ ] Bâtiment "Scanner d'alliance" : détection de flottes dans un rayon + alertes temps réel
- [ ] Bâtiment "Porte de téléportation" : liaison entre deux portes, transit "intouchable"
- [ ] Classement individuel et par alliance, vue globale et par catégorie

### Phase 10 — Marché
- [ ] Entités `Merchant` (PNJ, position fixe, insensible aux attaques), `MerchantHolding`, `MarketListing`
- [ ] Vente/achat de ressources : dépôt chez un marchand par les deux parties, validation à double livraison, retrait limité au bénéficiaire
- [ ] Vente de planètes (gel à la mise en vente, plus de production/pillage possible) + échange planète contre planète
- [ ] Écran "Marché" (annonces, mes transactions en cours chez les marchands, historique)
- [ ] *(différé — hors périmètre de cette phase)* Quêtes d'escorte : suite d'ordres avec destination/heure d'arrivée masquées jusqu'à résolution

### Phase 11 — Notifications & polish temps réel
- [ ] Centralisation des notifications (badge en direct, historique)
- [ ] Alerte "attaque entrante" avec compte à rebours live
- [ ] Optimisation des topics Mercure (un topic par planète/empire/alliance)

### Phase 12 — PvE (civilisations non-joueuses)
- [ ] Modéliser les empires NPC (`Empire` de type NPC + `NpcBehaviorProfile`)
- [ ] Civilisations mineures : génération de planètes/flottes NPC + logique de raid périodique (réutilise le moteur de déplacement/combat joueur)
- [ ] Back-office : administration complète des civilisations mineures (création, emplacement, composition de flotte, déclenchement manuel d'ordres d'attaque)
- [ ] Civilisations "boss" : job périodique de recalcul de flotte indexé sur le classement militaire (top 10)
- [ ] Comportement strictement défensif des boss (aucune attaque initiée, riposte uniquement)
- [ ] Back-office : écran de monitoring des boss (flotte courante, statut, historique des raids subis)
- [ ] Butin/récompenses spécifiques PvE + back-office de paramétrage (composition, fréquence, seuils)

### Phase 13 — Fair-play, admin, équilibrage
- [ ] Mode vacances + bouclier débutant
- [ ] Rate limiting sur les actions sensibles (envoi de flotte, messages)
- [ ] Back-office : modération, ajustement des taux de jeu, bannissement
- [ ] Modération du chat et de la messagerie (signalement, mise en sourdine, bannissement)
- [ ] Passe d'équilibrage (coûts, temps, matrice de classes, poids des angles d'attaque) avec des simulations automatisées

### Phase 14 — Qualité & mise en production
- [ ] Suite de tests fonctionnels (Panther) sur les parcours critiques (construction, combat, colonisation)
- [ ] Montée en charge : vérification des verrous, indexation DB, cache Redis
- [ ] Hébergement et exploitation (environnements, secrets, migrations Doctrine, monitoring, sauvegardes)
- [ ] Déploiement automatique : `staging` → préproduction, `main` → production (avec approbation manuelle), migrations, tests de fumée, retour arrière

---

## 7. Points à trancher plus tard (non bloquants pour démarrer)
- Rythme des vitesses de jeu (vitesse univers : x1, x3, x8...) — impacte directement l'équilibrage.
- Modèle économique éventuel (gratuit, cosmétiques, boost de confort type "file de construction supplémentaire").
- Charte graphique précise (palette, identité visuelle "Gardiens").
- Précision numérique des coordonnées (flottant vs entier) pour le calcul de la position d'une flotte en vol, et fréquence de rafraîchissement visuel d'une flotte en mouvement sur la carte.
- Liste précise des classes de vaisseaux et valeurs de la matrice de bonus/malus.
- Poids exact de chaque angle d'attaque (face/arrière/flanc) dans la résolution de combat.
- Nombre de planètes d'alliance autorisées par alliance, portée du scanner, nombre de portes de téléportation.
- Catalogue des quêtes/événements d'exploration au lancement.
- Fréquence de recalcul des flottes "boss" et nombre de civilisations mineures actives simultanément dans l'univers.
- Nombre/forme exacte des galaxies au lancement (a priori une seule) et règle d'ajout d'une nouvelle galaxie : critère de déclenchement (temps, saturation de la première), et existence ou non d'un lien de voyage direct entre deux galaxies (porte longue distance ? saut nécessitant une techno dédiée ?).
- Librairie de rendu retenue pour la carte interactive (PixiJS, D3.js, Leaflet détourné...) et stratégie de clustering exacte selon le niveau de zoom.
- Décision finale sur la rotation des systèmes/planètes (cf. §2.5) : conservée hors MVP ou intégrée plus tôt si elle s'avère structurante pour l'expérience.
- Règles d'engagement pour rejoindre une bataille (§4.7) : alliés uniquement, ou tout joueur en opportuniste ? Fenêtre de temps exacte entre détection d'une attaque et heure de résolution.
- Modalités précises de validation des quêtes joueur (§4.15) : quels types sont vérifiables automatiquement par le système (ex. escorte) vs nécessitent une validation manuelle du posteur, et quel recours en cas de litige.
- Placement des marchands dans la galaxie (nombre, algorithme de répartition, un marchand par système ou plus espacés ?) et durée maximale de rétention des ressources non retirées chez un marchand.
- Durée/limite du gel d'une planète mise en vente (délai maximum avant retrait automatique de l'annonce si personne n'achète) et fréquence maximale de revente pour limiter les abus.
- Peut-on volontairement lancer une flotte sans le carburant suffisant pour l'aller-retour (au risque de rester bloquée), ou le jeu impose-t-il toujours d'avoir le plein pour un aller simple minimum ? Une flotte de "Ravitaillement" peut-elle elle-même tomber en panne en chemin (cas récursif à gérer ou à exclure par design) ?
- Portée pratique du carnet d'ordres (nombre d'étapes maximum, possibilité d'annuler une étape à venir, comportement si une étape précédente échoue).
- Fusion des formations lors d'une bataille à plusieurs flottes (§4.7) : quand plusieurs joueurs rejoignent un même camp, chacun avec sa propre formation et sa propre trajectoire d'arrivée, comment agréger le tout pour un calcul d'angle d'attaque et de formation cohérent ? (ex. formation "de camp" reconstituée à partir des flottes participantes, ou angle calculé par flotte individuellement puis dégâts sommés) — nécessite un choix de modèle avant l'implémentation du moteur de combat.
- Chat en ligne : taille et durée de vie du tampon de messages récents, durée d'inactivité avant expiration d'un groupe, nombre maximum de participants par groupe de chat et par conversation de groupe, canal public unique ou un canal par galaxie si la population le justifie.
- Conception détaillée des quêtes d'escorte (différées, cf. §4.15) : mécanisme exact de dissimulation de la destination/heure d'arrivée aux autres joueurs, et modalités de validation "sans perte".
