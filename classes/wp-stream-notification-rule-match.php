<?php

class WP_Stream_Notification_Rule_Matcher {

	const CACHE_KEY = 'stream-notification-rules';

	public function __construct() {
		// Refresh cache on update/create of a new rule
		add_action( 'saved_stream_notification_rule', array( $this, 'refresh' ) );

		// Match all new type=stream records
		add_action( 'wp_stream_post_inserted', array( $this, 'match' ), 10, 2 );

		# DEBUG
		$this->rules();
	}

	public function refresh() {
		$this->rules( true );
	}

	public function rules( $force_refresh = false ) {
		# DEBUG
		$force_refresh = true;
		// Check if we have a valid cache
		if ( ! $force_refresh && false !== ( $rules = get_transient( self::CACHE_KEY ) ) ) {
			return $rules;
		}

		// Get rules
		$args = array(
			'type' => 'notification_rule',
			'ignore_context' => true,
			'records_per_page' => -1,
			'fields' => 'ID',
			'visibility' => 1, // Active rules only
			);
		$rules = stream_query( $args );
		$rules = wp_list_pluck( $rules, 'ID' );

		$rules = $this->format( $rules );

		// Cache the new rules
		set_transient( self::CACHE_KEY, $rules );
		return $rules;
	}

	public function match( $record_id, $log ) {

		$rules = $this->rules();
		$rule_match = array();

		foreach ( $rules as $rule_id => $rule ) {
			$rule_match[ $rule_id ] = $this->match_group( $rule['triggers'], $log );
		}

		$rule_match = array_keys( array_filter( $rule_match ) );
		$matching_rules = array_intersect_key( $rules, array_flip( $rule_match ) );

		$this->alert( $matching_rules, $log );
	}

	/**
	 * Match a group of chunked triggers against a log operation
	 * @param  array  $chunks Chunks of triggers, usually from group[triggers]
	 * @param  array  $log    Log operation array
	 * @return bool           Matching result
	 */
	private function match_group( $chunks, $log ) {
		// Separate triggers by 'AND'/'OR' relation, to be able to fail early
		// and not have to traverse the whole trigger tree
		foreach ( $chunks as $chunk ) {
			$results = array();
			foreach ( $chunk as $trigger ) {
				$is_group = isset( $trigger['triggers'] );

				if ( $is_group ) {
					$results[] = $this->match_group( $trigger['triggers'], $log );
				} else {
					$results[] = $this->match_trigger( $trigger, $log );
				}
			}
			// If the whole chunk fails, fail the whole group
			if ( count( array_filter( $results ) ) == 0 ) {
				return false;
			}
		}
		// If nothing fails, group matches
		return true;
	}

	public function match_trigger( $trigger, $log ) {
		# DEBUG
		return ( in_array( $trigger['type'], array( 'author_role' ) ) );
	}

	/**
	 * Format rules to be usable during the matching process
	 * @param  array  $rules Array of rule IDs
	 * @return array         Reformatted array of groups/triggers
	 */
	private function format( $rules ) {
		$output = array();
		foreach ( $rules as $rule_id ) {
			$output[ $rule_id ] = array();
			$rule = new WP_Stream_Notification_Rule( $rule_id );
			
			// Generate an easy-to-parse tree of triggers/groups
			$triggers = $this->generate_tree(
				$this->generate_flattened_tree(
					$rule->triggers,
					$rule->groups
				)
			);

			// Chunkify! @see generate_group_chunks
			$output[ $rule_id ]['triggers'] = $this->generate_group_chunks(
				$triggers[0]['triggers']
			);

			// Add alerts
			$output[ $rule_id ]['alerts'] = $rule->alerts;
		}
		return $output;
	}

	/**
	 * Return all of group's ancestors starting with the root
	 */
	private function generate_group_chain( $groups, $group_id ) {
		$chain = array();
		while ( isset( $groups[ $group_id ] ) ) {
			$chain[] = $group_id;
			$group_id = $groups[ $group_id ]['group'];
		}
		return array_reverse( $chain );
	}
	 
	/**
	 * Takes the groups and triggers and creates a flattened tree,
	 * which is an pre-order walkthrough of the tree we want to construct
	 * http://en.wikipedia.org/wiki/Tree_traversal#Pre-order
	 */
	private function generate_flattened_tree( $triggers, $groups ) {
		// Seed the tree with the universal group
		if ( ! isset( $groups[0] ) ) {
			$groups[0] = array( 'group' => null, 'relation' => 'and' );
		}
		$flattened_tree      = array( array( 'item' => $groups['0'], 'level' => 0, 'type' => 'group' ) );
		$current_group_chain = array( '0' );
		$level               = 1;
	 
		foreach ( $triggers as $key => $trigger ) {
			$active_group = end( $current_group_chain );
	 
			// If the trigger goes to any other than actually opened group, we need to traverse the tree first
			if ( $trigger['group'] != $active_group ) {
	 
				$trigger_group_chain   = $this->generate_group_chain( $groups, $trigger['group'] );
				$common_ancestors      = array_intersect( $current_group_chain, $trigger_group_chain );
				$newly_inserted_groups = array_diff( $trigger_group_chain, $current_group_chain );
				$steps_back            = $level - count( $common_ancestors );
	 
				// First take the steps back until we reach a common ancestor
				for ( $i = 0; $i < $steps_back; $i++ ) {
					array_pop( $current_group_chain );
					$level--;
				}
	 
				// Then go forward and generate group nodes until the trigger is ready to be inserted
				foreach ( $newly_inserted_groups as $group ) {
					$flattened_tree[] = array( 'item' => $groups[ $group ], 'level' => $level++, 'type' => 'group' );
					$current_group_chain[] = $group;
				}
			}
			// Now we're sure the trigger goes to a correct position
			$flattened_tree[] = array( 'item' => $trigger, 'level' => $level, 'type' => 'trigger' );
		}
	 
		return $flattened_tree;
	}
	 
	/**
	 * Takes the flattened tree and generates a proper tree
	 */
	private function generate_tree( $flattened_tree ) {
		// Our recurrent step
		$recurrent_step = function( $level, $i ) use ( $flattened_tree, &$recurrent_step ) {
			$return = array();
			for ( $i; $i < count( $flattened_tree ); $i++ ) {
				// If we're on the correct level, we're going to insert the node
				if ( $flattened_tree[$i]['level'] == $level ) {
					if ( $flattened_tree[$i]['type'] == 'trigger' ) {
						$return[] = $flattened_tree[$i]['item'];
						// If the node is a group, we need to call the recursive function
						// in order to construct the tree for us further
					} else {
						$return[] = array(
							'relation' => $flattened_tree[$i]['item']['relation'],
							'triggers' => call_user_func( $recurrent_step, $level + 1, $i + 1 ),
						);
					}
					// If we're on a lower level, we came back and we can return this branch
				} elseif ( $flattened_tree[$i]['level'] < $level ) {
					return $return;
				}
			}
			return $return;
		};
		return call_user_func( $recurrent_step, 0, 0 );
	}

	/**
	 * Split trigger trees by relation, so we can fail trigger trees early if
	 * an effective trigger is not matched
	 *
	 * A chunk would be a bulk of triggers that only matches if ANY of its 
	 * nested triggers are matched
	 * 
	 * @param  array  $group Group array, ex: array(
	 *   'relation' => 'and',
	 *   'trigger'  => array( arr trigger1, arr trigger2 )
	 *   );
	 * @return array         Chunks of triggers, split based on their relation
	 */
	private function generate_group_chunks( $triggers ) {
		$chunks = array();
		$current_chunk = -1;
		foreach ( $triggers as $trigger ) {
			// If is a group, chunks its children as well
			if ( isset( $trigger['triggers'] ) ) {
				$trigger['triggers'] = $this->generate_group_chunks( $trigger['triggers'] );
			}
			// If relation=and, start a new chunk, else join the previous chunk
			if ( $trigger['relation'] == 'and' ) {
				$chunks[] = array( $trigger );
				$current_chunk = count( $chunks ) - 1;
			} else {
				$chunks[ $current_chunk ][] = $trigger;
			}
		}
		return $chunks;
	}

	private function alert( $rules, $log ) {
		{echo '<pre>';var_dump( 'alerts via ' . implode( ',', array_keys( $rules ) ) );echo '</pre>';die();}
	}

}