<?php

/**
 *
 */
trait TermSyncSupport {

    /**
     * @param $term
     * @return bool
     */
    protected function checkIfTermIsSynced($term)
    {
        $cloned_term = get_term_by('slug', $term['slug'], $term['taxonomy']);
        return is_object($cloned_term) && ($cloned_term->term_id == $term['term_id']);
    }

    protected function tryToSyncTaxonomy($term)
    {
        $term_id = $term['term_id'];
        $cloned_term = get_term_by('slug', $term['slug'], $term['taxonomy']);
        // NON ESISTE
        if(!is_object($cloned_term)) {
            $this->insertNewTaxonomy($term);
            // TODO TESTARE GESTIONE CONFLITTO
        } elseif($this->checkIdConflict($term, $cloned_term)) {
            // I due termini hanno ids differenti
            // Controlliamo eventuali conflitti di ids
            $conflict_term = get_term($term_id);
            if($conflict_term) {
                $this->moveTermToLimbo($conflict_term);
            }
            // Aggiorniamo tutte le relazioni per mantenere id
            $this->launchSetOfTaxQuery($cloned_term->term_id, $term_id);
        }
    }

    protected function insertNewTaxonomy($term)
    {
        global $wpdb;
        $term_id = $term['term_id'];
        $term_name = $term['name'];
        $taxonomy = $term['taxonomy'];
        remove_action('create_term', [$this, 'sps_add_term']);
        $new_term = wp_insert_term( $term_name, $taxonomy, $term);
        if(is_array($new_term)) {
            // Controlliamo eventuali conflitti di ids
            $conflict_term = get_term($term_id);
            if($conflict_term) {
                $this->moveTermToLimbo($conflict_term);
            }
            // Aggiorniamo tutte le relazioni per mantenere id
            $this->launchSetOfTaxQuery($new_term['term_id'], $term_id);
            return $term_id;
        }
        return 0;
    }

    /**
     * @param array $request_term
     * @param WP_Term $production_term
     * @return bool
     */
    protected function checkIdConflict(array $request_term, WP_Term $production_term)
    {
        return ($production_term->term_id !== $request_term['term_id']) && ($production_term->slug === $request_term['slug']) && ($production_term->taxonomy === $request_term['taxonomy']);
    }

    protected function moveTermToLimbo($term)
    {
        global $wpdb;
        $last_id = $wpdb->get_var("SELECT term_id FROM $wpdb->terms ORDER BY term_id DESC LIMIT 1");
        $new_last_id = (int) $last_id + 1;
        $this->launchSetOfTaxQuery($term->term_id, $new_last_id);
        update_term_meta( $new_last_id, 'delta_sync', 'has_delta' );
    }

    /**
     * @param $old_id
     * @param $new_id
     * @return void
     */
    protected function launchSetOfTaxQuery($old_id, $new_id)
    {
        global $wpdb;
        $wpdb->update($wpdb->terms, array('term_id'=>$new_id), array('term_id'=>$old_id));
        $wpdb->update($wpdb->term_relationships, array('term_taxonomy_id'=>$new_id), array('term_taxonomy_id'=>$old_id));
        $wpdb->update($wpdb->term_taxonomy, array('term_id'=>$new_id), array('term_id'=>$old_id));
        $wpdb->update($wpdb->termmeta, array('term_id'=>$new_id), array('term_id'=>$old_id));
    }
}