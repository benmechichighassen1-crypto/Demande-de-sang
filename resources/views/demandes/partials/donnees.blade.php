{{-- Données saisies avec une action, en clair. --}}
@php
    $x = $a->donnees ?? [];
    $lignes = [];
    if (isset($x['numero_recu'])) $lignes[] = __('Reçu n° :num', ['num' => $x['numero_recu']]);
    if (! empty($x['paiement'])) $lignes[] = \App\Models\Demande::libellePaiement($x);
    if (isset($x['carte_groupage'])) $lignes[] = __('Carte de groupage : :reponse → :n tube(s)', ['reponse' => __($x['carte_groupage']), 'n' => $x['nb_tubes']]);
    if (isset($x['pieces'])) $lignes[] = __('Pièces :').' '.implode(', ', array_keys(array_filter($x['pieces'])));
    if (! empty($x['heure'])) $lignes[] = __('Heure :').' '.$x['heure'];
    if (isset($x['service_source']) || isset($x['service_destination'])) $lignes[] = $ref->service($x['service_source'] ?? null).' → '.$ref->service($x['service_destination'] ?? null).(! empty($x['transfert']) ? ' ('.__('transfert').')' : '');
    if (isset($x['etat_cts'])) $lignes[] = $x['etat_cts'] === 'instance' ? __('Poches gardées en instance au CTS') : __('Poches prêtes').(isset($x['nb_pretes']) ? ' : '.$x['nb_pretes'].'/'.($x['nb_demandees'] ?? '?') : '');
    if (isset($x['conforme'])) $lignes[] = $x['conforme'] === 'oui' ? __('Conforme') : __('NON CONFORME :').' '.($x['motif_non_conformite'] ?? '');
    if (isset($x['poches'])) $lignes[] = __('Poches :').' '.implode(', ', array_map(fn ($p) => $p['numero'].' '.$p['produit'], $x['poches'])).' ('.count($x['poches']).'/'.($x['nb_attendu'] ?? '?').')';
    if (! empty($x['bons_pa'])) $lignes[] = __('Sortie patient Clinisys :').' '.implode(', ', $x['bons_pa']).' · '.__(':n poche(s)', ['n' => count($x['poches_delivrees'] ?? [])]);
    if (isset($x['bon_depot'])) $lignes[] = __('Bon de dépôt').' '.$x['bon_depot'].(($x['source'] ?? '') === 'clinisys' ? ' ('.__('retrouvé dans Clinisys : :n poche(s)', ['n' => count($x['poches_clinisys'] ?? [])]).')' : '');
    if (! empty($x['ecarts']['manquantes'])) $lignes[] = '⚠ '.__('Dans le BD Clinisys mais pas au contrôle :').' '.implode(', ', $x['ecarts']['manquantes']);
    if (! empty($x['ecarts']['en_trop'])) $lignes[] = '⚠ '.__('Saisies au contrôle mais absentes du BD Clinisys :').' '.implode(', ', $x['ecarts']['en_trop']);
    if (isset($x['bons_clinisys'])) $lignes[] = __('Bon saisi absent de Clinisys').($x['bons_clinisys'] !== [] ? ' ('.__('bons de la demande :').' '.implode(', ', $x['bons_clinisys']).')' : '');
    if (isset($x['poches_stock_clinisys'])) $lignes[] = __('Stock BS Clinisys pour ce dossier :').' '.implode(', ', $x['poches_stock_clinisys']);
    if (isset($x['etat_dmi'])) $lignes[] = __('Demande DMI lue dans Clinisys (état :etat)', ['etat' => $x['etat_dmi']]);
    if (isset($x['poches_disponibles'])) $lignes[] = ($x['poches_disponibles'] === [] ? __('Aucune poche disponible dans l\'appli') : __('Poches disponibles :').' '.implode(', ', $x['poches_disponibles'])).(! empty($x['forcee']) ? ' — '.__('demande créée quand même :').' '.implode(' · ', array_filter([$x['motif_forcage'] ?? null, isset($x['motif_poche']) ? __(config('demandesang.motifs_poche_disponible.'.$x['motif_poche'], (string) $x['motif_poche'])) : null, $x['motif_poche_detail'] ?? null])) : '');
    if (isset($x['num_demande_clinisys'])) $lignes[] = 'N° '.$x['num_demande_clinisys'].' · '.__(':n poche(s)', ['n' => $x['nb_poches']]).' · '.$x['diagnostic'];
    if (isset($x['nature'])) $lignes[] = __(config('demandesang.natures.'.$x['nature']));
    if (isset($x['issue'])) foreach ($x['issue'] as $id => $v) { $lignes[] = __('Poche').' #'.$id.' : '.($v === 'transfusee' ? __('transfusée') : __('non utilisée (:motif)', ['motif' => __(config('demandesang.motifs_non_utilisation.'.($x['motif'][$id] ?? ''), '?'))])); }
    if (isset($x['poche'])) $lignes[] = __('Poche').' '.$x['poche'].' · '.__(config('demandesang.motifs_non_utilisation.'.$x['motif'])).($x['bon_prelevement'] ? ' · '.__('bon').' '.$x['bon_prelevement'] : '');
    if (isset($x['donneur'])) $lignes[] = __('Remis par :nom', ['nom' => $x['donneur']]);
@endphp
@foreach($lignes as $l)<div class="doux petit">{{ $l }}</div>@endforeach
