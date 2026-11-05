#include <LedControl.h>
#include <FastLED.h>

// --- Configuration ---
#define ENABLE_WS2812 1
#define WS_BRIGHT 32

#define DIN_PIN 11
#define CLK_PIN 13
#define CS_PIN 10
LedControl lc = LedControl(DIN_PIN, CLK_PIN, CS_PIN, 1);

#define DATA_PIN 6
#define NUM_LEDS 36 // 18 gauche (0-17) + 18 droite (18-35)
CRGB leds[NUM_LEDS];

// Intensité MAX7219 (0-15) : 15 = luminosité maximale (rapport cyclique 31/32).
// Attention : avec ISET = 2.2k (< 9.53k mini de la datasheet) le courant crête par
// segment est hors spec. Surveiller la température du MAX7219. Si il chauffe,
// redescendre à 10-12 ou remplacer R2 par ~10k.
#define MAX_INTENSITY 15

// Cadences (ms)
#define WS_FRAME_MS  100   // WS2812 : identique au rythme d'origine
#define MAX_FRAME_MS 50    // MAX7219 : résolution des séquences (chenillards)

// Conversion segment -> bit du registre MAX7219
// seg: 0=A,1=B,2=C,3=D,4=E,5=F,6=G  ->  bit6=A ... bit0=G (bit7=DP)
#define SEGBIT(seg) (1 << (6 - (seg)))

// =====================================================================
//                              WS2812
// =====================================================================

// --- Variables d'animation ---
unsigned long lastScenarioChange = 0;
int currentScenario = 0;
uint8_t hueOffset = 0;
#define NUM_WS_SCENARIOS 25

// Variables Tetris
int stackL = 0;
int dropPos = 0;
unsigned long lastDropTime = 0;

// Variables Rebond / K2000
float ballPos = 0;
float ballSpeed = 0.6;
bool movingRight = true;

// Variables CASCADE (remplissage par chute depuis le haut)
int fillStack = 0;        // nombre de LED déjà empilées en bas
int fillPos = 0;          // position de la LED en chute (0 = haut : U2 / U20)
int fillSpeed = 1;        // vitesse de chute (accélère comme la gravité)
uint8_t fillPhase = 0;    // 0 = chute, 1 = clignotement final
uint8_t fillFlash = 0;
bool holdScenario = false; // bloque le changement de scénario tant que la séquence n'est pas finie

CRGB getBarColor(int i) {
  if (i < 10) return CRGB::Green;
  if (i < 15) return CRGB::Orange;
  return CRGB::Red;
}

// Couleur de la LED empilée n° i (0 = tout en bas)
CRGB cascadeColor(uint8_t mode, int i) {
  switch (mode) {
    case 0:  return (CRGB)CHSV(i * 14, 255, 255);        // arc-en-ciel
    case 1:  return (CRGB)CHSV(i * 4, 255, 255);         // rouge -> jaune (feu)
    case 3:  return CRGB::Gold;                          // or (style Tetris)
    default: return (CRGB)CHSV(128 + i * 2, 255, 255);   // cyan -> bleu (glace)
  }
}

// Une image de la cascade : une LED tombe du haut (U2/U20) vers le bas (U19/U37),
// s'empile, jusqu'à remplissage complet, puis clignote et la séquence se termine.
void cascade_step(uint8_t mode, bool whiteHead) {
  holdScenario = true;
  FastLED.clear();

  if (fillPhase == 0) {
    int landing = 17 - fillStack;       // premier emplacement libre en bas
    fillPos += fillSpeed;
    if (fillSpeed < 5) fillSpeed++;
    if (fillPos >= landing) {           // la LED se pose
      fillStack++;
      fillPos = 0;
      fillSpeed = 1;
      if (fillStack >= 18) { fillPhase = 1; fillFlash = 0; }
    }
  }

  if (fillPhase == 0) {
    // LEDs empilées
    for (int i = 0; i < fillStack; i++) {
      leds[17 - i] = cascadeColor(mode, i);
      leds[35 - i] = cascadeColor(mode, i);
    }
    // LED en chute + petite traînée
    CRGB c = whiteHead ? CRGB(CRGB::White) : cascadeColor(mode, fillStack);
    leds[fillPos] = c;
    leds[18 + fillPos] = c;
    if (fillPos > 0) {
      CRGB t = c; t.nscale8(60);
      leds[fillPos - 1] = t;
      leds[18 + fillPos - 1] = t;
    }
  } else {
    // Tout est plein : clignotement final
    fillFlash++;
    if (((fillFlash / 2) % 2) == 0) {
      for (int i = 0; i < 18; i++) {
        leds[17 - i] = cascadeColor(mode, i);
        leds[35 - i] = cascadeColor(mode, i);
      }
    }
    if (fillFlash >= 12) {              // séquence terminée -> on libère le scénario
      fillStack = 0; fillPos = 0; fillSpeed = 1; fillPhase = 0; fillFlash = 0;
      holdScenario = false;
      lastScenarioChange = 0;           // force le changement de scénario à l'image suivante
    }
  }
}

void update_bargrafs() {
  unsigned long now = millis();
  hueOffset++;

  // Changement de scénario toutes les 6 secondes (sauf séquence en cours qui doit finir)
  if (!holdScenario && (now - lastScenarioChange > 6000)) {
    currentScenario = random(0, NUM_WS_SCENARIOS);
    lastScenarioChange = now;
    stackL = 0;
    dropPos = 0;
    ballPos = 0;
    fillStack = 0; fillPos = 0; fillSpeed = 1; fillPhase = 0; fillFlash = 0;
  }

  int valL, valR;

  switch (currentScenario) {
    case 0: // VU-METRE CLASSIQUE (Monte du bas vers le haut)
      valL = random(0, 18); valR = random(0, 18);
      for (int i = 0; i < 18; i++) {
        leds[17 - i] = (i < valL) ? getBarColor(i) : CRGB::Black;   // i = 0 en bas (U19)
        leds[35 - i] = (i < valR) ? getBarColor(i) : CRGB::Black;   // i = 0 en bas (U37)
      }
      break;

    case 1: // ARC-EN-CIEL
      for (int i=0; i<18; i++) {
        leds[i] = (CRGB)CHSV(hueOffset + (i*10), 255, 255);
        leds[i+18] = (CRGB)CHSV(hueOffset + (i*10), 255, 255);
      }
      break;

    case 2: // FEU
      for (int i=0; i<18; i++) {
        leds[i] = (random(0,20)>i) ? (CRGB)CHSV(random(0,20), 255, 255) : CRGB::Black;
        leds[i+18] = (random(0,20)>i) ? (CRGB)CHSV(random(0,20), 255, 255) : CRGB::Black;
      }
      break;

    case 3: // SCANNER CYAN SIMPLE
      FastLED.clear();
      valL = map(beat8(40), 0, 255, 0, 17);
      leds[valL] = CRGB::Cyan; leds[18+17-valL] = CRGB::Cyan;
      break;

    case 4: // TETRIS OR : LED blanche qui tombe du haut, s'empile en or jusqu'au remplissage complet
      cascade_step(3, true);
      break;

    case 5: // GLACIER
      valL = random(5, 19); valR = random(5, 19);
      for (int i=0; i<18; i++) {
        leds[17 - i] = (i < valL) ? CRGB::DeepSkyBlue : CRGB::Black;
        leds[35 - i] = (i < valR) ? CRGB::Navy : CRGB::Black;
      }
      break;

    case 6: // POLICE
      FastLED.clear();
      if (random(0,5) == 0) {
        fill_solid(leds, 18, CRGB::Red);
        fill_solid(leds+18, 18, CRGB::Blue);
      }
      break;

    case 7: // ONDES MAGENTA
      for (int i=0; i<18; i++) {
        int b = beatsin8(30, 0, 255, 0, i*10);
        leds[i] = (CRGB)CHSV(200, 255, b);
        leds[i+18] = (CRGB)CHSV(200, 255, b);
      }
      break;

    case 8: // CASCADE ARC-EN-CIEL (remplace REMPLISSAGE LENT) : tombe du haut, s'empile en bas
      cascade_step(0, false);
      break;

    case 9: // TETRIS PSYCHEDELIQUE : LED blanche qui tombe, empilement arc-en-ciel, remplissage complet
      cascade_step(0, true);
      break;

    case 10: // REBONDS INVERSES
      FastLED.clear();
      if (movingRight) ballPos += ballSpeed; else ballPos -= ballSpeed;
      if (ballPos >= 17) { movingRight = false; ballPos = 17; }
      if (ballPos <= 0) { movingRight = true; ballPos = 0; }
      valL = (int)ballPos;
      leds[valL] = CRGB::White;
      leds[18 + (17 - valL)] = CRGB::White;
      if (movingRight && valL > 0) { leds[valL-1] = CRGB::DarkViolet; leds[18+(17-valL)+1] = CRGB::DarkCyan; }
      else if (!movingRight && valL < 17) { leds[valL+1] = CRGB::DarkViolet; leds[18+(17-valL)-1] = CRGB::DarkCyan; }
      break;

    case 11: // STYLE K2000
      fadeToBlackBy(leds, NUM_LEDS, 60);
      {
        int posK = beatsin8(25, 0, 17);
        leds[posK] = CRGB::Red;
        leds[18 + (17 - posK)] = CRGB::Red;
        leds[posK] += CRGB(100, 0, 0);
        leds[18 + (17 - posK)] += CRGB(100, 0, 0);
      }
      break;

    case 12: // EXPLOSION CENTRALE
      FastLED.clear();
      {
        int centerPos = beatsin8(60, 0, 10);
        if (9 - centerPos >= 0)  leds[9 - centerPos] = CRGB::Gold;
        if (9 + centerPos <= 17) leds[9 + centerPos] = CRGB::Gold;
        if (27 - centerPos >= 18) leds[27 - centerPos] = CRGB::Orange;
        if (27 + centerPos <= 35) leds[27 + centerPos] = CRGB::Orange;
      }
      break;

    case 13: // SUPERNOVA
      fadeToBlackBy(leds, NUM_LEDS, 80);
      {
        int wave = (millis() / 40) % 10;
        if (9 - wave >= 0)  leds[9 - wave] = CHSV(wave * 25, 255, 255);
        if (9 + wave <= 17) leds[9 + wave] = CHSV(wave * 25, 255, 255);
        if (27 - wave >= 18) leds[27 - wave] = CHSV(160 + (wave * 10), 255, 255);
        if (27 + wave <= 35) leds[27 + wave] = CHSV(160 + (wave * 10), 255, 255);
      }
      break;

    case 14: // MATRIX
      fadeToBlackBy(leds, NUM_LEDS, 50);
      for (int c = 0; c < 2; c++) {
        int startIdx = (c == 0) ? 0 : 18;
        if (random(0, 3) == 0) {
          int pos = random(0, 18);
          leds[startIdx + pos] = CRGB::Green;
          if (pos > 0) leds[startIdx + pos - 1] = CRGB::DarkGreen;
        }
      }
      break;

    case 15: // DOUBLE SCANNER
      FastLED.clear();
      {
        int scan1 = beatsin8(35, 0, 17);
        int scan2 = beatsin8(45, 0, 17);
        leds[scan1] = CRGB::Cyan;
        leds[18 + (17 - scan1)] = CRGB::Cyan;
        leds[18 + scan2] = CRGB::DeepPink;
      }
      break;

    case 16: // PULSATION REACTOR
      {
        uint8_t brightness = beatsin8(50, 20, 255);
        for(int i = 0; i < 18; i++) {
          leds[i]    = CHSV(15, 255, brightness);
          leds[i+18] = CHSV(160, 255, brightness);
        }
      }
      break;

    case 17: // STROBOSCOPE
      FastLED.clear();
      if (random(0, 4) == 0) {
        for(int i = 0; i < NUM_LEDS; i++) {
          leds[i] = (random(0, 2) == 0) ? CRGB::Magenta : CRGB::Yellow;
        }
      }
      break;

    case 18: // LA CHENILLE LUMINEUSE
      {
        int shift = (millis() / 60) % 18;
        for (int i = 0; i < 18; i++) {
          byte hue = (i + shift) * 13;
          leds[i]      = CHSV(hue, 255, 255);
          leds[18 + i] = CHSV(hue + 128, 255, 255);
        }
      }
      break;

    case 19: // ALERTE IMPÉRATRICE
      FastLED.clear();
      if ((millis() / 150) % 2 == 0) {
        fill_solid(leds, 18, CRGB::Crimson);
        fill_solid(leds + 18, 18, CRGB::White);
      } else {
        fill_solid(leds, 18, CRGB::White);
        fill_solid(leds + 18, 18, CRGB::Crimson);
      }
      break;

    case 20: // SCANNER LASER BLEU GLACÉ
      fadeToBlackBy(leds, NUM_LEDS, 40);
      {
        int posLaser = beatsin8(30, 0, 17);
        leds[posLaser] = CRGB::White;
        leds[posLaser] += CRGB(0, 150, 255);

        int posLaserR = beatsin8(20, 0, 17);
        leds[18 + posLaserR] = CRGB::White;
        leds[18 + posLaserR] += CRGB(0, 255, 255);
      }
      break;

    case 21: // RADAR SCI-FI
      FastLED.clear();
      {
        int radarPos = (millis() / 50) % 18;
        leds[radarPos] = CRGB::SpringGreen;
        if (radarPos > 0) leds[radarPos - 1] = CRGB::DarkGreen;

        int radarPos2 = 17 - ((millis() / 50) % 18);
        leds[18 + radarPos2] = CRGB::Orange;
        if (radarPos2 < 17) leds[18 + radarPos2 + 1] = CRGB::DarkRed;
      }
      break;

    case 22: // CASCADE FEU (rouge -> jaune)
      cascade_step(1, false);
      break;

    case 23: // CASCADE GLACE (cyan -> bleu)
      cascade_step(2, false);
      break;

    case 24: // PLUIE : gouttes bleues qui tombent du haut vers le bas
      for (int i = 17; i > 0; i--) {
        leds[i] = leds[i - 1];
        leds[i].nscale8(225);
        leds[18 + i] = leds[18 + i - 1];
        leds[18 + i].nscale8(225);
      }
      leds[0]  = (random(0, 4) == 0) ? (CRGB)CHSV(150 + random(0, 30), 255, 255) : CRGB(CRGB::Black);
      leds[18] = (random(0, 4) == 0) ? (CRGB)CHSV(150 + random(0, 30), 255, 255) : CRGB(CRGB::Black);
      break;
  }
}

// =====================================================================
//                              MAX7219
// =====================================================================
// Tableaux {DIG, segment}. Chaque repère (Y1, V3, RE2...) = 2 LED
const uint8_t Y[10][2] = {{0,6},{1,6},{2,6},{3,6},{4,6},{5,6},{6,6},{7,6},{0,5},{1,5}};
const uint8_t V[10][2] = {{2,5},{3,5},{4,5},{5,5},{6,5},{7,5},{0,4},{1,4},{2,4},{3,4}};
const uint8_t R[4][2]  = {{4,4},{5,4},{6,4},{7,4}};
const uint8_t LB[6][2] = {{3,0},{3,1},{3,2},{3,3},{4,0},{4,1}};
const uint8_t LW[6][2] = {{2,0},{2,1},{2,2},{2,3},{4,2},{4,3}};
const uint8_t D78[2][2] = {{5,0},{5,1}};   // D7 = DIG5/A, D8 = DIG5/B

// Groupes : 0-4 = Y1..Y5, 5-9 = V1..V5, 10-11 = RE1, RE2
#define N_GROUPS 12

// Ligne "bargraph" (ordre de gauche à droite, modifiable) : RE1, RE2, Y4, Y5, V4, V5
#define BAR_LEN 6
const uint8_t BAR[BAR_LEN] = {10, 11, 3, 4, 8, 9};

#define NUM_MAX_SCENARIOS 12

uint8_t rows[8];
uint8_t prevRows[8] = {0};
// LED bleues L1..L6 (D1-D6 = LB) et blanches (D9-D14 = LW) : séquences indépendantes
uint8_t lbScen = 0, lwScen = 0;
unsigned long lbStart = 0, lwStart = 0, lbDur = 0, lwDur = 0;
bool lbSt[6] = {0};  unsigned long lbNx[6] = {0};
bool lwSt[6] = {0};  unsigned long lwNx[6] = {0};

// LED rouges D7 / D8 : séquences indépendantes (voir tableau D78 plus haut)
uint8_t d78Scen = 0;
unsigned long d78Start = 0, d78Dur = 0;
bool d78St[2] = {0};  unsigned long d78Nx[2] = {0};

uint8_t maxScen = 0;
unsigned long maxScenStart = 0;
unsigned long maxScenDuration = 0;  // 0 -> choix d'un scénario dès la 1re image

bool gState[N_GROUPS];
unsigned long gNext[N_GROUPS];
int barLevel = 0;
unsigned long barNext = 0;

static void ledOn(const uint8_t t[][2], uint8_t idx) {
  rows[t[idx][0]] |= SEGBIT(t[idx][1]);
}

static void groupOn(uint8_t g) {
  if (g < 5)       { ledOn(Y, 2 * g);        ledOn(Y, 2 * g + 1); }
  else if (g < 10) { ledOn(V, 2 * (g - 5));  ledOn(V, 2 * (g - 5) + 1); }
  else if (g < 12) { ledOn(R, 2 * (g - 10)); ledOn(R, 2 * (g - 10) + 1); }
}

// Allume UNE seule des 2 LED d'un repère (h = 0 ou 1)
static void halfOn(uint8_t g, uint8_t h) {
  if (g < 5)       ledOn(Y, 2 * g + h);
  else if (g < 10) ledOn(V, 2 * (g - 5) + h);
  else if (g < 12) ledOn(R, 2 * (g - 10) + h);
}

// Séquences pour un groupe de 6 LED (tbl = LB ou LW).
// Chaque groupe choisit au hasard entre 2 séquences (sA ou sB) et la garde 5 à 10 s :
//   0 = chenillard aller-retour   1 = scintillement vif   2 = alternance pair/impair
static void runL(const uint8_t tbl[][2], uint8_t &scen, uint8_t sA, uint8_t sB, bool reverse,
                 unsigned long &start, unsigned long &dur, bool *st, unsigned long *nx,
                 unsigned long now) {
  if (now - start > dur) {
    scen = random(0, 2) ? sA : sB;
    start = now;
    dur = random(5000, 10001);
    for (int i = 0; i < 6; i++) { st[i] = random(0, 2); nx[i] = now + random(60, 300); }
  }
  unsigned long t = now - start;

  switch (scen) {
    case 0: // CHENILLARD aller-retour (110 ms par pas)
      {
        int p = (t / 110) % 10;
        if (p >= 6) p = 10 - p;
        if (reverse) p = 5 - p;
        ledOn(tbl, p);
      }
      break;

    case 1: // SCINTILLEMENT VIF : chaque LED change d'état toutes les 80 à 400 ms
      for (int i = 0; i < 6; i++) {
        if (now >= nx[i]) { st[i] = !st[i]; nx[i] = now + random(80, 400); }
        if (st[i]) ledOn(tbl, i);
      }
      break;

    case 2: // ALTERNANCE pair / impair toutes les 250 ms
      for (int i = 0; i < 6; i++) {
        if ((i % 2) == (int)((t / 250) % 2)) ledOn(tbl, i);
      }
      break;
  }
}

// Séquences pour D7 / D8 (rouges), toujours actives, 3 séquences au hasard (4 à 8 s chacune) :
//   0 = alternance D7 / D8 (250 ms)   1 = scintillement indépendant   2 = clignotement ensemble (300 ms)
static void runD78(unsigned long now) {
  if (now - d78Start > d78Dur) {
    d78Scen = random(0, 3);
    d78Start = now;
    d78Dur = random(4000, 8001);
    for (int i = 0; i < 2; i++) { d78St[i] = random(0, 2); d78Nx[i] = now + random(60, 300); }
  }
  unsigned long t = now - d78Start;

  switch (d78Scen) {
    case 0:
      ledOn(D78, (t / 250) % 2);
      break;

    case 1:
      for (int i = 0; i < 2; i++) {
        if (now >= d78Nx[i]) { d78St[i] = !d78St[i]; d78Nx[i] = now + random(100, 450); }
        if (d78St[i]) ledOn(D78, i);
      }
      break;

    case 2:
      if (((t / 300) % 2) == 0) { ledOn(D78, 0); ledOn(D78, 1); }
      break;
  }
}

static void update_max7219() {
  unsigned long now = millis();

  // --- Changement de scénario (8 à 15 s) ---
  if (now - maxScenStart > maxScenDuration) {
    uint8_t n;
    do { n = random(0, NUM_MAX_SCENARIOS); } while (n == maxScen);
    maxScen = n;
    maxScenStart = now;
    maxScenDuration = random(8000, 15001);
    for (int g = 0; g < N_GROUPS; g++) {
      gState[g] = random(0, 2);
      gNext[g] = now + random(300, 1500);
    }
    barLevel = 0;
    barNext = now;
  }
  unsigned long t = now - maxScenStart;

  memset(rows, 0, sizeof(rows));

  switch (maxScen) {
    case 0: // CLIGNOTEMENT 1 s : tout allumé 1 s / éteint 1 s
      if (((t / 1000) % 2) == 0) for (int g = 0; g < N_GROUPS; g++) groupOn(g);
      break;

    case 1: // ALTERNANCE 2 s : Y allumées 2 s, puis V + RE 2 s
      if (((t / 2000) % 2) == 0) {
        for (int g = 0; g < 5; g++) groupOn(g);
      } else {
        for (int g = 5; g < N_GROUPS; g++) groupOn(g);
      }
      break;

    case 2: // CHENILLARD sur les 12 repères (Y1..Y5, V1..V5, RE1, RE2)
      groupOn((t / 150) % N_GROUPS);
      break;

    case 3: // ALLER-RETOUR sur la ligne RE1, RE2, Y4, Y5, V4, V5
      {
        int p = (t / 130) % (2 * BAR_LEN - 2);
        if (p >= BAR_LEN) p = 2 * BAR_LEN - 2 - p;
        groupOn(BAR[p]);
      }
      break;

    case 4: // BARGRAPH type VU-mètre sur la ligne (niveau qui fluctue)
      if (now >= barNext) {
        barNext = now + random(100, 260);
        barLevel += random(-2, 3);
        if (barLevel < 0) barLevel = 0;
        if (barLevel > BAR_LEN) barLevel = BAR_LEN;
      }
      for (int i = 0; i < barLevel; i++) groupOn(BAR[i]);
      break;

    case 5: // BARGRAPH qui se remplit puis se vide
      {
        int lv = (t / 250) % (2 * BAR_LEN);
        if (lv > BAR_LEN) lv = 2 * BAR_LEN - lv;
        for (int i = 0; i < lv; i++) groupOn(BAR[i]);
      }
      break;

    case 6: // ALEATOIRE CALME : chaque repère change d'état tous les 0,4 à 1,8 s
      for (int g = 0; g < N_GROUPS; g++) {
        if (now >= gNext[g]) {
          gState[g] = !gState[g];
          gNext[g] = now + random(400, 1800);
        }
        if (gState[g]) groupOn(g);
      }
      break;

    case 7: // ROTATION DES FAMILLES : Y 1 s, V 1 s, RE 1 s
      {
        int ph = (t / 1000) % 3;
        if (ph == 0)      { for (int g = 0; g < 5; g++) groupOn(g); }
        else if (ph == 1) { for (int g = 5; g < 10; g++) groupOn(g); }
        else              { groupOn(10); groupOn(11); }
      }
      break;

    // ---- Séquences utilisant les 2 LED de chaque repère ----

    case 8: // BASCULE : dans chaque repère, les 2 LED alternent toutes les 500 ms
      for (int g = 0; g < N_GROUPS; g++) halfOn(g, (t / 500) % 2);
      break;

    case 9: // VAGUE : même bascule, mais décalée d'un repère à l'autre
      for (int g = 0; g < N_GROUPS; g++) halfOn(g, ((t / 300) + g) % 2);
      break;

    case 10: // CHENILLARD FIN : 24 LED une par une (les 2 LED de chaque repère à la suite)
      {
        int idx = (t / 80) % (2 * N_GROUPS);
        halfOn(idx / 2, idx % 2);
      }
      break;

    case 11: // BARGRAPH FIN : 12 niveaux sur la ligne (une LED à la fois), monte puis descend
      {
        int lv = (t / 120) % (4 * BAR_LEN);
        if (lv > 2 * BAR_LEN) lv = 4 * BAR_LEN - lv;
        for (int i = 0; i < lv; i++) halfOn(BAR[i / 2], i % 2);
      }
      break;
  }

  // --- LED bleues L1..L6 : chenillard aller-retour ou scintillement vif ---
  runL(LB, lbScen, 0, 1, false, lbStart, lbDur, lbSt, lbNx, now);
  // --- LED blanches D9..D14 : chenillard (sens inverse) ou alternance pair/impair ---
  runL(LW, lwScen, 0, 2, true,  lwStart, lwDur, lwSt, lwNx, now);
  // --- LED rouges D7 / D8 ---
  runD78(now);

  // --- Envoi uniquement si changement ---
  for (int r = 0; r < 8; r++) {
    if (rows[r] != prevRows[r]) {
      lc.setRow(0, r, rows[r]);
      prevRows[r] = rows[r];
    }
  }
}

// =====================================================================

unsigned long lastMaxUpdate = 0;
unsigned long lastWsUpdate = 0;

void setup() {
  lc.shutdown(0, false);
  lc.setScanLimit(0, 7);
  lc.setIntensity(0, MAX_INTENSITY);
  lc.clearDisplay(0);

  if (ENABLE_WS2812) {
    FastLED.addLeds<NEOPIXEL, DATA_PIN>(leds, NUM_LEDS);
    FastLED.setBrightness(WS_BRIGHT);
    FastLED.clear();
    FastLED.show();
  }
  randomSeed(analogRead(0));
}

void loop() {
  unsigned long now = millis();

  // MAX7219 : séquences calmes, sans bloquer
  if (now - lastMaxUpdate >= MAX_FRAME_MS) {
    lastMaxUpdate = now;
    update_max7219();
  }

  // WS2812 : cadence 100 ms inchangée
  if (ENABLE_WS2812 && (now - lastWsUpdate >= WS_FRAME_MS)) {
    lastWsUpdate = now;
    update_bargrafs();
    FastLED.show();
  }
}
