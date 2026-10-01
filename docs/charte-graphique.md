# Space Guardians — Charte graphique

Référence visuelle du jeu. Toute nouvelle interface doit s'y conformer ; toute évolution de la charte se fait **dans ce fichier** (et dans les maquettes) avant d'arriver dans le code.

- Maquettes de référence (canevas Design) : https://claude.ai/artifact/8Xenwki1vgP9njaAxhqqnB
  - Page **Écrans** : un artboard par écran du §5.4 du cahier des charges, plus Inscription, Connexion et l'événement d'exploration.
  - Page **Composants** : la bibliothèque partagée et la planche « Fondations ».
  - Page **Identité — logos** : les six pistes étudiées ; la « D · Sentinelle » est retenue (§6).

---

## 1. Direction : « console de commandement Gardien »

Le joueur est aux commandes d'un ancien ordre de Gardiens. L'interface est un HUD sombre et précis, pas un décor de science-fiction chargé.

| Principe | Traduction |
|---|---|
| Le fond est l'espace | Fonds quasi noirs bleutés, aucun dégradé décoratif sur les panneaux. Les dégradés sont réservés aux illustrations (planètes, étoiles, cœur galactique). |
| L'or est rare et précieux | L'or « deutérium primordial » (`--sg-gold`) signale l'action principale, l'élément actif et l'héritage Gardien. Jamais en aplat décoratif. |
| Le cyan est vivant | Le cyan (`--sg-cyan`) signale ce qui bouge en temps réel : flottes en vol, recherche en cours, chat, compteurs Mercure. |
| Les chiffres sont des instruments | Tout nombre, coordonnée, durée ou compte à rebours est en police mono. |
| Angles coupés | Les blocs ont un coin supérieur gauche et un coin inférieur droit biseautés (voir §5). Pas de coins arrondis, sauf pour les objets circulaires (planètes, pastilles de présence, interrupteurs). |

À éviter : emoji, bordures latérales colorées sur les cartes, dégradés sur les panneaux, ombres portées marquées (seule exception : le widget de chat flottant).

---

## 2. Couleurs

### 2.1 Jetons

À déclarer une seule fois (ex. `assets/styles/tokens.css`) et à utiliser partout via les variables.

```css
:root {
  /* Surfaces (du plus profond au plus proche) */
  --sg-space:        #070B14; /* fond de page */
  --sg-panel-deep:   #0A101D; /* barre du haut, navigation, fond de carte, champs « encastrés » */
  --sg-panel:        #0D1424; /* panneaux, cartes, listes */
  --sg-panel-raised: #131C30; /* survol, élément actif, onglet sélectionné */
  --sg-track:        #1A2540; /* fond de jauge, séparateurs de lignes */
  --sg-line:         #22304F; /* bordures des panneaux */
  --sg-line-strong:  #34466F; /* bordures de champs et de boutons secondaires */

  /* Texte */
  --sg-text:   #E8EEF9; /* texte principal */
  --sg-text-2: #A3B1CF; /* texte secondaire, descriptions */
  --sg-text-3: #8091B5; /* libellés, métadonnées (≥ 4.5:1 sur --sg-panel) */
  --sg-text-4: #6E7FA3; /* horodatages, en-têtes de groupe — 12 px minimum */

  /* Accents */
  --sg-gold:        #F2B84B; /* action principale, actif, héritage Gardien */
  --sg-gold-ink:    #1A1206; /* texte posé sur l'or */
  --sg-cyan:        #6FDCEE; /* temps réel, information, en vol */
  --sg-violet:      #B9A3FF; /* vaisseaux, classes, PNJ */

  /* Ressources */
  --sg-metal:     #C3CCDD;
  --sg-crystal:   #7FB8FF;
  --sg-deut:      #4FE0C2;
  --sg-energy:    #FFD84D;
  --sg-debris:    #FFB168;

  /* États */
  --sg-danger:  #FF8A8A;  --sg-danger-bg:  #1F0C10;  --sg-danger-line:  #8A2F38;
  --sg-success: #6EE0A6;  --sg-success-bg: #0F2A1F;  --sg-success-line: #2A6A4C;
  --sg-warning: #FFB168;  --sg-warning-bg: #2E1E10;  --sg-warning-line: #7A4E22;
  --sg-info:    #6FDCEE;  --sg-info-bg:    #0E2430;  --sg-info-line:    #24606F;
}
```

### 2.2 Tons de pastille (`Chip`)

Chaque ton = couleur de texte, fond, bordure. Ce sont les seules combinaisons autorisées pour signaler un statut.

| Ton | Texte | Fond | Bordure | Usage |
|---|---|---|---|---|
| `neutral` | `#A3B1CF` | `#131C30` | `#34466F` | niveau, coordonnée, statut sans enjeu |
| `gold` | `#F2B84B` | `#2A2110` | `#6B5222` | planète mère, sélection, stationnée, fondateur |
| `cyan` | `#6FDCEE` | `#0E2430` | `#24606F` | en vol, disponible, diplomate, allié |
| `red` | `#FF8A8A` | `#2E1216` | `#7A2A31` | hostile, attaque, bataille |
| `green` | `#6EE0A6` | `#0F2A1F` | `#2A6A4C` | libre, validé, pacte actif, en ligne |
| `orange` | `#FFB168` | `#2E1E10` | `#7A4E22` | immobilisée, en attente, débris |
| `violet` | `#B9A3FF` | `#1E1836` | `#4E3F86` | classe de vaisseau, civilisation PNJ |

### 2.3 Sémantique de jeu

| Concept | Couleur |
|---|---|
| Vous / vos planètes | or |
| Alliance et pactes | cyan |
| Empire hostile, attaque entrante | rouge |
| Civilisation PNJ (mineure) | violet |
| Boss / Gardien PNJ | or avec halo |
| Marchand | vert |
| Planète libre | contour vert pointillé |
| Champ de débris | orange |
| Système non exploré | `#3A4A70` ; occupé : `#7A8AAE` |

Les états distingués ne reposent jamais sur la seule teinte : ils diffèrent aussi en luminosité ou par un second indice (pointillé, icône, libellé).

---

## 3. Typographie

Google Fonts : `Chakra Petch` (500, 600, 700), `IBM Plex Sans` (400, 500, 600), `JetBrains Mono` (400, 500, 600).

```css
--sg-font-display: 'Chakra Petch', sans-serif;
--sg-font-body:    'IBM Plex Sans', sans-serif;
--sg-font-mono:    'JetBrains Mono', monospace;
```

| Rôle | Police | Taille / graisse | Particularités |
|---|---|---|---|
| Titre d'écran (H1) | Chakra Petch | 36 px / 700 | 44 px sur Inscription et Connexion |
| Titre de panneau (H2) | Chakra Petch | 20–24 px / 600 | |
| Nom d'entité (carte) | Chakra Petch | 17 px / 600 | |
| Titre de section | Chakra Petch | 15 px / 600 | MAJUSCULES, interlettrage 0.14em |
| Surtitre (kicker) | Chakra Petch | 11 px / 600 | MAJUSCULES, 0.2em, `--sg-text-3` |
| Libellé de champ ou de colonne | Chakra Petch | 10 px / 600 | MAJUSCULES, 0.16em |
| Bouton | Chakra Petch | 14 px (12 px en `sm`) / 600 | MAJUSCULES, 0.06em |
| Texte courant | IBM Plex Sans | 14–15 px / 400 | interligne 1.5 |
| Texte secondaire | IBM Plex Sans | 12–13 px / 400 | `--sg-text-2` |
| Compte à rebours principal | JetBrains Mono | 18–28 px / 600 | couleur du contexte |
| Chiffres, coordonnées | JetBrains Mono | 11–15 px | `[1:342:7]` toujours entre crochets |

Format des nombres : espace insécable comme séparateur de milliers (`1 284 560`), signe moins typographique (`−42`). Durées : `HH:MM:SS` pour un compte à rebours, `2 h 14 min` pour une estimation.

---

## 4. Espacement et mise en page

- Grille de base **4 px** ; pas courants : 4, 6, 8, 10, 12, 14, 16, 18, 20, 24, 28, 32.
- Écart entre sections : 24 px. Écart dans un panneau : 12–16 px. Marge intérieure de panneau : 18–24 px.
- **Gabarit applicatif** (tous les écrans connectés) :
  - `TopBar` sur toute la largeur, 64 px minimum ;
  - `SideNav` fixe de 232 px à gauche ;
  - contenu fluide, marge intérieure de 28 × 32 px, marge basse ≥ 120 px pour ne pas passer sous le chat ;
  - `ChatDock` flottant en bas à droite, à 24 px du bord.
- Gabarit d'accueil (Inscription, Connexion) : illustration galactique fluide à gauche, formulaire de 560 px à droite.
- Grilles de cartes : `repeat(auto-fill, minmax(250px, 1fr))`, écart 16 px.
- Sous ~900 px de large, la navigation passe au-dessus du contenu (flex-wrap) ; aucun défilement horizontal de la page, sauf dans l'arbre de recherche et la carte.

---

## 5. Formes

Angle coupé = `clip-path` sur deux coins opposés :

```css
.sg-cut-8  { clip-path: polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px); }
.sg-cut-10 { clip-path: polygon(10px 0, 100% 0, 100% calc(100% - 10px), calc(100% - 10px) 100%, 0 100%, 0 10px); }
.sg-cut-14 { clip-path: polygon(14px 0, 100% 0, 100% calc(100% - 14px), calc(100% - 14px) 100%, 0 100%, 0 14px); }
```

| Taille | Usage |
|---|---|
| 8 px | boutons, éléments de navigation, onglets |
| 10 px | éléments de file, nœuds de recherche, choix d'événement |
| 14 px | cartes, panneaux, bandeaux d'alerte |

Autres motifs :
- **Losange** : carré de 6–8 px tourné à 45°, en or. Puce de titre de section, marqueur d'élément actif, numéro d'étape d'un carnet d'ordres.
- **Hexagone** : avatars d'empire et d'alliance (`clip-path: polygon(25% 0, 75% 0, 100% 50%, 75% 100%, 25% 100%, 0 50%)`).
- **Jauges** : barre pleine de 3–4 px (6 px dans les classements) sur `--sg-track`, sans arrondi.
- **Fond technique** des vignettes : grille de points 1 px tous les 14 px (`radial-gradient(#1F2A44 1px, transparent 1px)`).

---

## 6. Logo : « Sentinelle »

Un obélisque-balise en losange, traversé par une orbite inclinée : la sentinelle dressée devant le sanctuaire. Il reprend le losange des titres de section ; l'or porte l'héritage Gardien, le cyan de l'orbite le mouvement en temps réel.

### 6.1 Fichiers de référence

Sources dans [`docs/identite/`](identite/), grille 64 × 64. Toute déclinaison part de ces fichiers.

| Fichier | Usage |
|---|---|
| [`logo.svg`](identite/logo.svg) | version de référence, à partir de 48 px |
| [`logo-petit.svg`](identite/logo-petit.svg) | traits renforcés, de 24 à 48 px (barre du haut : 30 px) |
| [`logo-mono.svg`](identite/logo-mono.svg) | une couleur via `currentColor` : `--sg-gold-ink` sur fond or, `--sg-text` sur photo |
| [`favicon-32.svg`](identite/favicon-32.svg) | favicon 32 px et icône d'application : losange plein, orbite épaissie |
| [`favicon-16.svg`](identite/favicon-16.svg) | favicon 16 px : losange plein seul |

### 6.2 Construction

- Losange : sommets (32 ; 5), (45 ; 32), (32 ; 59), (19 ; 32) ; contour or 3,5, jointures arrondies ; rempli de la couleur du fond pour masquer l'orbite derrière lui.
- Croix intérieure : axes vertical et horizontal, or 1,5.
- Orbite : ellipse 28 × 9 centrée, inclinée de −18°, cyan 2,5.
- Version petite taille : épaisseurs 4,5 / 2 / 3,5.

### 6.3 Avec le nom

- Logotype sur deux lignes, aligné à gauche du symbole, écart égal à 30 % de la hauteur du symbole :
  - `SPACE` : Chakra Petch 700, interlettrage 0.22em, `--sg-text` ;
  - `GUARDIANS` : Chakra Petch 600, environ 55 % de la taille de `SPACE`, interlettrage 0.44–0.5em, `--sg-gold`.
- Version horizontale uniquement ; pas de nom sous le symbole.

### 6.4 Règles

- Zone de protection autour du symbole : la moitié de sa largeur.
- Taille minimale : 16 px (favicon), 24 px pour le symbole complet avec orbite.
- Fonds autorisés : `--sg-space`, `--sg-panel-deep`, `--sg-panel` en couleur ; or `--sg-gold` en version monochrome.
- Interdits : changer les couleurs, supprimer l'orbite au-delà de 16 px, l'incliner, ajouter ombre, dégradé ou contour, le poser sur un fond clair en couleur.

---

## 7. Iconographie

- Icônes au trait, grille 24 × 24, trait de 1,6 px (1,8 px sous 16 px), extrémités et jointures arrondies, `fill: none`, couleur `currentColor`.
- Tailles : 14 (inline dans le texte), 16 (boutons), 18–20 (navigation, barre du haut), 56 (illustration de carte, trait 1,1 px).
- Les ressources ont une icône et une couleur fixes : métal = cube, cristal = gemme, deutérium = goutte, énergie = éclair.
- Une icône seule dans un bouton porte toujours un `aria-label`.
- Le jeu d'icônes de référence est sur la planche « Fondations » des maquettes. Pour l'intégration, privilégier un sprite SVG unique (`symfony/ux-icons` ou un sprite maison).

---

## 8. Composants

Chaque composant de maquette correspond à un futur **Twig Component** (`templates/components/`) ; ceux qui ont un état serveur deviennent des **Live Components**.

| Composant | Rôle | Variantes / états | Implémentation prévue |
|---|---|---|---|
| `Btn` | bouton | `primary` (or), `secondary` (cyan), `ghost`, `danger` ; tailles `md` 44 px / `sm` 36 px ; icône, icône seule, pleine largeur, désactivé (opacité 0.45) | Twig Component |
| `Chip` | statut court | 7 tons (§2.2), point optionnel | Twig Component |
| `ResourceCost` | coût ou quantité de ressources | métal, cristal, deutérium, énergie ; ressource manquante en rouge | Twig Component |
| `SectionTitle` | titre de section | titre + métadonnée à droite | Twig Component |
| `TopBar` | barre globale | planète active, 4 ressources (valeur, débit/h, jauge de stockage), énergie en déficit, badges notifications et messages | Live Component + Turbo Stream via Mercure |
| `SideNav` | navigation | élément actif, badges, liste des planètes (alerte d'attaque), bouclier débutant | Twig Component |
| `ChatDock` | chat en ligne | replié / déplié ; onglets Public, Alliance, Privé, Groupe ; présence ; non persistant | contrôleur Stimulus, `data-turbo-permanent` |
| `QueueItem` | élément de file | bâtiment (or), recherche (cyan), vaisseau (violet), défense (vert) ; progression, fin prévue, annulation et remboursement | Live Component (compte à rebours Stimulus) |
| `EntityCard` | bâtiment, vaisseau, défense | `ready`, `building`, `short` (ressources insuffisantes), `locked` (prérequis) ; quantité ; classe ; statistiques | Twig Component |
| `TechNode` | nœud de l'arbre de recherche | `acquired` (or), `available` (cyan), `researching` (cyan + barre), `locked` (pointillé) ; sélectionné | Twig Component, positions calculées côté serveur |
| `FleetRow` | flotte et carnet d'ordres | `vol`, `stationnee`, `immobilisee`, `bataille` ; étapes faites / en cours / à venir / bloquées ; jauges trajet et carburant | Live Component |
| `AlertBanner` | alerte pleine largeur | `attack`, `info`, `success` ; compte à rebours ; deux actions | Turbo Stream via Mercure |

Règles communes :
- Un écran n'invente pas de variante locale : si un besoin manque, on fait évoluer le composant et ce tableau.
- Une seule action `primary` (or) par zone visible.
- Les états « en cours » affichent toujours : progression, temps restant (mono) et heure de fin.
- Les actions irréversibles ou coûteuses (annulation, suppression, pacte non résiliable) précisent leur conséquence dans le texte voisin.

---

## 9. Patrons d'écran

| Patron | Écrans | Règle |
|---|---|---|
| En-tête d'écran | tous | surtitre (contexte, coordonnées) + H1 + actions à droite ; onglets de catégorie en contrôle segmenté |
| Panneau de détail latéral | Recherche, Classement, Marché | 300–340 px à droite, bordure or si l'élément est sélectionné |
| Tableau de données | Alliance, Marché, Classement, Galaxie | en-tête en libellés 10 px, lignes de 48 px minimum, séparateur `--sg-track` |
| Carte interactive | Galaxie | rendu SVG/Canvas ; légende en bas à gauche, zoom en haut à droite, infobulle de système bordée d'or |
| Trois colonnes | Messagerie | dossiers 200 px, liste 340 px, lecture fluide |

---

## 10. Accessibilité

- Contraste du texte ≥ 4.5:1 (≥ 3:1 au-delà de 24 px). Les couleurs de §2.1 sont validées sur `--sg-panel` ; ne pas éclaircir les fonds.
- Cibles tactiles ≥ 44 px (36 px toléré pour les actions secondaires des listes denses).
- Vrais éléments natifs : `<button>`, `<a href>`, `<input>` + `<label>`, `role="switch"`, `role="tab"`, `aria-current="page"`.
- Les alertes d'attaque utilisent `role="alert"` ; les comptes à rebours ne sont pas annoncés à chaque seconde.
- Aucune information portée par la couleur seule (cf. §2.3).

---

## 11. Points ouverts

- Illustrations des vaisseaux et bâtiments (aujourd'hui : pictogrammes au trait).
- Version mobile dédiée (aujourd'hui : mise en page fluide seulement).
- Thème clair : non prévu.
