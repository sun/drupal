<?php

/**
 * @file
 * Contains Drupal\views\Plugin\views\filter\LanguageFilter.
 */

namespace Drupal\views\Plugin\views\filter;

/**
 * Provides filtering by language.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("language")
 */
class LanguageFilter extends InOperator {

  public function getValueOptions() {
    if (!isset($this->value_options)) {
      $this->value_title = t('Language');
      $languages = array(
        '***CURRENT_LANGUAGE***' => t("Current user's language"),
        '***DEFAULT_LANGUAGE***' => t("Default site language"),
      );
      $languages = array_merge($languages, views_language_list());
      $this->value_options = $languages;
    }
  }

}
