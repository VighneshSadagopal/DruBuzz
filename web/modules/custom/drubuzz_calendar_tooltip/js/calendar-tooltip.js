/**
 * @file
 * Rich hover tooltips for the Events Calendar (FullCalendar) view.
 *
 * This file runs as a plain IIFE (not a Drupal behavior) so that it executes
 * before `Drupal.behaviors.fullcalendar` builds the calendar on DOMContentLoaded.
 * It registers an `eventDidMount` callback in the calendar options; that
 * callback colours the event by its post category and wires up a tooltip
 * showing the title, category and body.
 */
(function (drupalSettings) {
  'use strict';

  if (!drupalSettings || !drupalSettings.fullcalendar) {
    return;
  }

  // A unique colour per post category. Keys are the stored field values.
  var CATEGORY_COLORS = {
    reminder: '#f59e0b',
    announcment: '#3b82f6',
    highlight: '#10b981',
    sponsor_promotion: '#8b5cf6',
    speaker_promotion: '#ec4899'
  };
  var DEFAULT_COLOR = '#3788d8';

  function categoryColor(category) {
    return (category && CATEGORY_COLORS[category]) || DEFAULT_COLOR;
  }

  var tooltipEl = null;
  var activeAnchor = null;
  var hideTimer = null;

  function hide() {
    if (hideTimer) {
      window.clearTimeout(hideTimer);
      hideTimer = null;
    }
    if (tooltipEl) {
      tooltipEl.remove();
      tooltipEl = null;
      activeAnchor = null;
    }
  }

  // Delayed hide so the pointer can travel from the event chip onto the
  // tooltip (which is now interactive) without it vanishing.
  function scheduleHide() {
    if (hideTimer) {
      window.clearTimeout(hideTimer);
    }
    hideTimer = window.setTimeout(hide, 220);
  }

  function cancelHide() {
    if (hideTimer) {
      window.clearTimeout(hideTimer);
      hideTimer = null;
    }
  }

  function position() {
    if (!tooltipEl || !activeAnchor) {
      return;
    }
    var rect = activeAnchor.getBoundingClientRect();
    var tip = tooltipEl.getBoundingClientRect();
    var maxLeft = document.documentElement.clientWidth - tip.width - 8;
    var left = Math.max(8, Math.min(rect.left, maxLeft));
    var top = rect.bottom + 8;

    if (top + tip.height > document.documentElement.clientHeight && rect.top - tip.height - 8 > 0) {
      top = rect.top - tip.height - 8;
    }

    tooltipEl.style.left = (left + window.scrollX) + 'px';
    tooltipEl.style.top = (top + window.scrollY) + 'px';
  }

  function show(info) {
    hide();
    var event = info.event;
    var props = event.extendedProps || {};

    tooltipEl = document.createElement('div');
    tooltipEl.className = 'fc-tooltip';

    var title = document.createElement('div');
    title.className = 'fc-tooltip__title';
    title.textContent = event.title;
    tooltipEl.appendChild(title);

    if (props.categoryLabel) {
      var cat = document.createElement('span');
      cat.className = 'fc-tooltip__category';
      cat.textContent = props.categoryLabel;
      cat.style.backgroundColor = categoryColor(props.category);
      tooltipEl.appendChild(cat);
    }

    if (props.body) {
      var bodyEl = document.createElement('div');
      bodyEl.className = 'fc-tooltip__body';
      // `body` is already sanitised server-side by the field's text format.
      bodyEl.innerHTML = props.body;
      tooltipEl.appendChild(bodyEl);
    }

    if (props.postUrl) {
      var actions = document.createElement('div');
      actions.className = 'fc-tooltip__actions';

      var btn = document.createElement('a');
      btn.className = 'fc-tooltip__btn' + (props.postExists ? '' : ' fc-tooltip__btn--new');
      btn.href = props.postUrl;
      btn.textContent = props.postExists ? 'Open post' : 'Create post';
      actions.appendChild(btn);

      if (props.postExists && props.postLabel) {
        var meta = document.createElement('span');
        meta.className = 'fc-tooltip__postmeta';
        meta.textContent = props.postLabel;
        actions.appendChild(meta);
      }

      tooltipEl.appendChild(actions);
    }

    // Keep the tooltip open while the pointer is over it.
    tooltipEl.addEventListener('mouseenter', cancelHide);
    tooltipEl.addEventListener('mouseleave', scheduleHide);

    document.body.appendChild(tooltipEl);
    activeAnchor = info.el;
    position();
  }

  function eventDidMount(info) {
    // Colour the event chip on the calendar to represent its post category.
    var color = categoryColor(info.event.extendedProps && info.event.extendedProps.category);
    info.el.style.backgroundColor = color;
    info.el.style.borderColor = color;

    info.el.addEventListener('mouseenter', function () {
      cancelHide();
      show(info);
    });
    info.el.addEventListener('mouseleave', scheduleHide);
    info.el.addEventListener('focusin', function () {
      cancelHide();
      show(info);
    });
    info.el.addEventListener('focusout', scheduleHide);
  }

  window.addEventListener('scroll', hide, true);
  window.addEventListener('resize', hide);

  Object.keys(drupalSettings.fullcalendar).forEach(function (key) {
    var viewSettings = drupalSettings.fullcalendar[key];
    if (!viewSettings || !viewSettings.options) {
      return;
    }
    var existing = viewSettings.options.eventDidMount;
    viewSettings.options.eventDidMount = function (info) {
      if (typeof existing === 'function') {
        existing(info);
      }
      eventDidMount(info);
    };
  });
})(window.drupalSettings);
