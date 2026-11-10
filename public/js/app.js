// Scripts communs de l'appli Demandes de sang (gardés en cache par le navigateur, version dans l'adresse).
// Onglets d'étapes : clic = seulement les sections de cette étape ; « je suis où » dans le fil d'Ariane ;
// filtre gardé dans l'adresse (#etape-<clé>) et dans le navigateur pour le retrouver après une validation.
(function () {
    const entete = document.querySelector('header');
    const hauteur = () => document.documentElement.style.setProperty('--haut-entete', (entete ? entete.offsetHeight : 0) + 'px');
    hauteur(); window.addEventListener('resize', hauteur);
    // Listes : les champs de filtre présents dans l'adresse sont surlignés.
    const params = new URLSearchParams(location.search);
    document.querySelectorAll('form.filtres select, form.filtres input:not([type=hidden])').forEach(function (el) {
        if (el.name && params.get(el.name)) { el.classList.add('filtre-actif'); }
    });
    // Barres d'onglets : « étapes » (sections data-bloc) et, pour un compte à plusieurs rôles, « par rôle »
    // (tâches data-roles). Les deux filtres se combinent ; les compteurs de chaque barre suivent le filtre de l'autre.
    const T = (window.DS || {}).T || {};
    const lireMemo = k => { try { return localStorage.getItem('onglet.' + k); } catch (e) { return null; } };
    const ecrireMemo = (k, v) => { try { localStorage.setItem('onglet.' + k, v); } catch (e) {} };
    const barres = [...document.querySelectorAll('[data-onglets]')].map(function (barre) {
        return { el: barre, ecran: barre.dataset.onglets, parRole: !! barre.dataset.cible, cle: 'toutes',
                 boutons: [...barre.querySelectorAll('[data-onglet]')], fil: document.querySelector('[data-fil="' + barre.dataset.onglets + '"] span') };
    });
    const etapes = barres.find(b => ! b.parRole), roles = barres.find(b => b.parRole);
    const sections = [...document.querySelectorAll('section[data-bloc]')];
    const taches = roles ? [...document.querySelectorAll('[data-roles]')] : [];
    const blocDe = t => t.closest('section[data-bloc]')?.dataset.bloc;
    const aRole = (t, r) => r === 'toutes' || (t.dataset.roles || '').split(' ').includes(r);
    const dansBloc = (t, b) => b === 'toutes' || blocDe(t) === b;
    let vide = null;
    if (roles) { vide = document.createElement('div'); vide.className = 'carte'; vide.hidden = true; roles.el.nextElementSibling.after(vide); }

    function afficher(barre) {
        const b = barre.boutons.find(x => x.dataset.onglet === barre.cle) || barre.boutons[0];
        barre.boutons.forEach(x => { x.classList.toggle('actif', x === b); x.setAttribute('aria-selected', x === b); });
        if (barre.fil) { barre.fil.textContent = barre.cle === 'toutes' ? T.toutes : b.dataset.libelle + ' (' + b.querySelector('.n').textContent + ')';
                         barre.fil.parentNode.style.setProperty('--fil', getComputedStyle(b).getPropertyValue('--c')); }
    }
    function appliquer() {
        const cat = etapes ? etapes.cle : 'toutes', role = roles ? roles.cle : 'toutes';
        if (! roles) {
            sections.forEach(s => { s.hidden = cat !== 'toutes' && s.dataset.bloc !== cat; });
        } else {
            taches.forEach(t => { t.hidden = ! aRole(t, role); });
            sections.forEach(s => { s.hidden = (cat !== 'toutes' && s.dataset.bloc !== cat) || (role !== 'toutes' && ! s.querySelector('[data-roles]:not([hidden])')); });
            // Compteurs croisés : chaque pastille compte les tâches qui passent aussi l'autre filtre (0 = grisée).
            const compter = (fn) => taches.filter(fn).length;
            roles.boutons.forEach(x => { const n = compter(t => aRole(t, x.dataset.onglet) && dansBloc(t, cat)); x.querySelector('.n').textContent = n; x.classList.toggle('vide', n === 0); });
            if (etapes) etapes.boutons.forEach(x => { const n = compter(t => dansBloc(t, x.dataset.onglet) && aRole(t, role)); x.querySelector('.n').textContent = n; x.classList.toggle('vide', n === 0); });
            // Rien à afficher : dire pourquoi et proposer les étapes où ce rôle a des tâches.
            const visibles = taches.filter(t => aRole(t, role) && dansBloc(t, cat)).length;
            vide.hidden = visibles > 0 || role === 'toutes';
            if (! vide.hidden) {
                const nomRole = roles.boutons.find(x => x.dataset.onglet === role).dataset.libelle;
                const nomCat = etapes && cat !== 'toutes' ? etapes.boutons.find(x => x.dataset.onglet === cat).dataset.libelle : T.vue;
                const ailleurs = etapes ? etapes.boutons.filter(x => x.dataset.onglet !== 'toutes' && x.dataset.onglet !== cat)
                    .map(x => [x, compter(t => dansBloc(t, x.dataset.onglet) && aRole(t, role))]).filter(([, n]) => n > 0) : [];
                vide.innerHTML = '';
                vide.append(etapes ? T.aucune.replace(':role', nomRole).replace(':etape', nomCat) + (ailleurs.length ? T.voir : '.') : T.rien.replace(':role', nomRole));
                ailleurs.forEach(([x, n], i) => { const a = document.createElement('a'); a.href = '#'; a.textContent = x.dataset.libelle + ' (' + n + ')';
                    a.onclick = e => { e.preventDefault(); choisir(etapes, x.dataset.onglet); }; if (i) vide.append(', '); vide.append(a); });
            }
        }
        barres.forEach(afficher);
    }
    function choisir(barre, cle, garder = true) {
        barre.cle = barre.boutons.some(x => x.dataset.onglet === cle) ? cle : 'toutes';
        // Rôle sans tâche dans l'étape choisie : on repasse l'étape sur « Toutes ».
        if (barre === roles && etapes && barre.cle !== 'toutes' && ! taches.some(t => aRole(t, barre.cle) && dansBloc(t, etapes.cle))) {
            etapes.cle = 'toutes'; ecrireMemo(etapes.ecran, 'toutes');
        }
        if (garder) { ecrireMemo(barre.ecran, barre.cle); history.replaceState(null, '', barre.cle === 'toutes' ? location.pathname + location.search : '#etape-' + barre.cle); }
        appliquer();
    }
    barres.forEach(barre => barre.boutons.forEach(x => x.addEventListener('click', () => choisir(barre, x.dataset.onglet))));
    const h = location.hash.replace(/^#(etape|bloc)-/, '');
    barres.forEach(barre => { barre.cle = barre.boutons.some(x => x.dataset.onglet === h) ? h : (lireMemo(barre.ecran) || 'toutes');
                              if (! barre.boutons.some(x => x.dataset.onglet === barre.cle)) barre.cle = 'toutes'; });
    if (roles && etapes && roles.cle !== 'toutes' && ! taches.some(t => aRole(t, roles.cle) && dansBloc(t, etapes.cle))) etapes.cle = 'toutes';
    appliquer();
})();
// Tableaux sans <thead> : la première ligne d'en-têtes (que des <th>) devient un <thead>, répété à l'impression.
document.querySelectorAll('table').forEach(function (t) {
    if (t.tHead) return;
    const r = t.rows[0];
    if (r && r.cells.length && [...r.cells].every(c => c.tagName === 'TH')) { const h = t.createTHead(); h.appendChild(r); }
});
// Grand tableau (plus de 15 lignes) : son propre cadre de défilement, en-tête collé en haut du cadre. Les autres restent simples.
document.querySelectorAll('table').forEach(function (t) {
    if (! t.tHead || t.rows.length <= 16 || t.parentElement.classList.contains('cadre-tableau')) return;
    t.closest('.table-defile')?.classList.add('a-cadre');
    const cadre = document.createElement('div');
    cadre.className = 'cadre-tableau';
    t.before(cadre);
    cadre.appendChild(t);
});
// Cadre proche du bas de page (notes, pagination après le tableau) : sa hauteur laisse la place à ce qui suit,
// pour que son en-tête ne passe jamais sous le bloc collant quand on arrive en bas de la page.
function ajusterCadres() {
    const cadres = document.querySelectorAll('.cadre-tableau');
    cadres.forEach(cadre => cadre.style.removeProperty('--apres'));
    const fin = document.documentElement.scrollHeight;
    cadres.forEach(function (cadre) {
        const apres = fin - (cadre.getBoundingClientRect().bottom + window.scrollY);
        if (apres > 0 && apres < 300) cadre.style.setProperty('--apres', Math.ceil(apres) + 'px');
    });
}
window.addEventListener('load', ajusterCadres);
window.addEventListener('resize', ajusterCadres);
// Bloc collant de l'écran (pastilles + filtres, ou barre d'onglets) : sa hauteur décale l'en-tête collant des tableaux ;
// il se réduit en une ligne compacte dès qu'on a défilé au-delà de son emplacement.
(function () {
    const bloc = document.querySelector('.collant') || document.querySelector('.onglets');
    if (! bloc) return;
    const racine = document.documentElement;
    const mesurer = () => racine.style.setProperty('--haut-collant', bloc.offsetHeight + 'px');
    mesurer();
    let mesureAttente = false;
    if (window.ResizeObserver) new ResizeObserver(() => { if (! mesureAttente) { mesureAttente = true; requestAnimationFrame(() => { mesureAttente = false; mesurer(); }); } }).observe(bloc);
    if (! bloc.classList.contains('collant')) return;
    const repere = document.createElement('div');
    bloc.before(repere);
    const entete = (parseInt(getComputedStyle(racine).getPropertyValue('--haut-entete')) || 0) + 1;
    // Repère juste avant le bloc : passé sous l'en-tête de l'appli = on a défilé, le bloc se réduit.
    let attente = false;
    const suivre = () => { attente = false; bloc.classList.toggle('reduit', repere.getBoundingClientRect().top < entete - 20); };
    window.addEventListener('scroll', () => { if (! attente) { attente = true; requestAnimationFrame(suivre); } }, { passive: true });
    suivre();
})();
// Recherche dans la page (tableaux de bord) : masque les cartes / lignes qui ne contiennent pas le texte (sans accents ni majuscules).
const sansAccent = t => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
document.querySelectorAll('input[data-filtre-local]').forEach(function (champ) {
    const compte = champ.form && champ.form.querySelector('[data-filtre-compte]');
    const filtrer = () => {
        const q = sansAccent(champ.value.trim());
        const elements = [...document.querySelectorAll(champ.dataset.filtreLocal)].filter(e => ! champ.form.contains(e));
        let n = 0;
        elements.forEach(e => { const ok = ! q || sansAccent(e.textContent).includes(q); e.style.display = ok ? '' : 'none'; if (ok) n++; });
        if (compte) compte.textContent = q ? ((window.DS || {}).resultats || ':n').replace(':n', n) : '';
        // Blocs de la page sans aucun résultat : masqués pendant la recherche (les résultats passent en haut).
        document.querySelectorAll('section[data-bloc]').forEach(function (bloc) {
            const lignes = elements.filter(e => bloc.contains(e));
            bloc.style.display = q && lignes.length && ! lignes.some(e => e.style.display !== 'none') ? 'none' : '';
        });
        const x = champ.parentNode.querySelector('button'); if (x) x.style.display = champ.value ? '' : 'none';
    };
    let delai;
    champ.addEventListener('input', () => { clearTimeout(delai); delai = setTimeout(filtrer, 200); });
});
// Petite croix dans le champ de recherche des filtres : vide la recherche seule et relance le filtre.
document.querySelectorAll('form.filtres input[type=search]').forEach(function (champ) {
    const cadre = document.createElement('span');
    cadre.className = 'recherche-x';
    champ.before(cadre);
    cadre.appendChild(champ);
    const local = champ.hasAttribute('data-filtre-local');
    if (! champ.value && ! local) return;
    const x = document.createElement('button');
    x.type = 'button'; x.textContent = '×'; x.title = ((window.DS || {}).effacerRecherche || '×'); x.setAttribute('aria-label', x.title);
    if (local) x.style.display = 'none';
    x.addEventListener('click', () => { champ.value = ''; if (local) { champ.dispatchEvent(new Event('input')); champ.focus(); } else { champ.form.requestSubmit ? champ.form.requestSubmit() : champ.form.submit(); } });
    cadre.appendChild(x);
});
// Écrans de suivi : toutes les minutes (onglet visible seulement), demande si quelque chose a changé. Jamais de rechargement
// automatique : une pastille propose d'actualiser, la page et la saisie en cours ne bougent pas.
(function () {
    const repere = document.querySelector('meta[name=suivi-empreinte]');
    if (! repere) return;
    let pastille = null;
    const verifier = () => {
        if (document.visibilityState !== 'visible' || pastille) return;
        fetch(repere.dataset.url, { headers: { Accept: 'application/json' } }).then(r => r.ok ? r.json() : null).then(function (d) {
            if (! d || ! repere.content || d.empreinte === repere.content || pastille) return;
            pastille = document.createElement('button');
            pastille.type = 'button'; pastille.className = 'pastille-suivi non-imprime';
            pastille.textContent = (window.DS || {}).nouvelles || '↻';
            pastille.addEventListener('click', () => location.reload());
            document.body.appendChild(pastille);
        }).catch(() => {});
    };
    // Première vérification juste après l'affichage : c'est elle qui relit Clinisys (au plus une fois par minute), pas la page.
    setTimeout(verifier, 1500);
    setInterval(verifier, 60000);
    document.addEventListener('visibilitychange', verifier);
})();
// Fiche demande : en enregistrant une étape, on revient au même endroit de la page, sur l'étape qu'on vient de faire.
(function () {
    const cle = 'defilement:' + location.pathname;
    document.addEventListener('submit', function (e) {
        if ((e.target.method || '').toLowerCase() === 'post') {
            try { sessionStorage.setItem(cle, String(window.scrollY)); } catch (x) {}
        }
    }, true);
    let y = null;
    try { y = sessionStorage.getItem(cle); sessionStorage.removeItem(cle); } catch (x) {}
    const faite = document.querySelector('.etape-juste-faite');
    if (faite) { faite.scrollIntoView({ block: 'center' }); }
    else if (y !== null) { window.scrollTo(0, parseInt(y, 10) || 0); }
})();
