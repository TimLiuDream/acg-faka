(function () {
    'use strict';

    const root = document.querySelector('[data-codex-reset-monitor]');
    if (!root) return;

    const q = selector => root.querySelector(selector);
    const stateNode = q('[data-reset-state]');
    const contentNode = q('[data-reset-content]');
    const lang = typeof getVar === 'function' ? String(getVar('LANG') || '') : '';
    const english = lang.toLowerCase().startsWith('en');
    const locale = english ? 'en-US' : 'zh-CN';
    const tr = text => typeof i18n === 'function' ? i18n(text) : text;
    const timeZone = 'Asia/Shanghai';

    let snapshot = null;
    let selectedDate = '';
    let calendarMonth = null;

    function node(tag, className, text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined && text !== null) element.textContent = String(text);
        return element;
    }

    function safeDate(value) {
        const date = new Date(String(value || ''));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function parts(value) {
        const date = safeDate(value);
        if (!date) return null;
        const map = {};
        new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23'
        }).formatToParts(date).forEach(part => {
            if (part.type !== 'literal') map[part.type] = part.value;
        });
        return map;
    }

    function dateKey(value) {
        const valueParts = parts(value);
        return valueParts ? `${valueParts.year}-${valueParts.month}-${valueParts.day}` : '';
    }

    function eventDate(event) {
        if (/^\d{4}-\d{2}-\d{2}$/.test(String(event.occurredOn || ''))) return event.occurredOn;
        return dateKey(event.confirmedAt || event.schedule?.from || event.createdAt || event.updatedAt);
    }

    function eventSortValue(event) {
        const key = eventDate(event);
        if (!key) return 0;
        const precise = safeDate(
            event.confirmedAt || event.posts?.[0]?.publishedAt || event.schedule?.from || event.updatedAt || event.createdAt
        );
        const dayValue = Date.parse(`${key}T00:00:00+08:00`);
        return dayValue + (precise ? (precise.getTime() % 86400000) : 0);
    }

    function displayDate(key, options) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(key || ''))) return '—';
        const [year, month, day] = key.split('-').map(Number);
        return new Intl.DateTimeFormat(locale, options).format(new Date(Date.UTC(year, month - 1, day, 12)));
    }

    function displayDateTime(value, options) {
        const date = safeDate(value);
        return date ? new Intl.DateTimeFormat(locale, Object.assign({timeZone}, options)).format(date) : '—';
    }

    function kindLabel(event) {
        return tr(event.type === 'reset_credit' ? '发重置卡' : '全员重置');
    }

    function statusLabel(event) {
        return tr(event.status === 'confirmed' ? '已确认' : '已预告');
    }

    function eventTitle(event) {
        if (!english) return event.title || kindLabel(event);
        return `${kindLabel(event)} · ${statusLabel(event)}`;
    }

    function sourceText(post) {
        if (!post) return '';
        return english ? (post.originalText || post.text || '') : (post.text || post.originalText || '');
    }

    function groupEvents(events) {
        const groups = new Map();
        events.forEach(event => {
            const key = eventDate(event);
            if (!key) return;
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(event);
        });
        return groups;
    }

    function setLink(link, url) {
        if (typeof url === 'string' && /^https:\/\//.test(url)) {
            link.href = url;
            link.hidden = false;
        } else {
            link.removeAttribute('href');
            link.hidden = true;
        }
    }

    function showState(title, detail, canRetry) {
        stateNode.replaceChildren();
        const icon = node('span', 'ln-codex-state__icon');
        icon.textContent = '!';
        const copy = node('div');
        copy.append(node('strong', '', title), node('small', '', detail));
        stateNode.append(icon, copy);
        if (canRetry) {
            const retry = node('button', '', tr('重新加载'));
            retry.type = 'button';
            retry.addEventListener('click', load);
            stateNode.append(retry);
        }
        stateNode.hidden = false;
        contentNode.hidden = true;
    }

    function renderLatest(event) {
        const key = eventDate(event);
        const dateParts = parts(event.confirmedAt || event.schedule?.from || event.createdAt || event.updatedAt);
        q('[data-latest-kind]').textContent = kindLabel(event);
        const status = q('[data-latest-status]');
        status.textContent = statusLabel(event);
        status.className = `ln-codex-status is-${event.status}`;
        q('[data-latest-date]').textContent = displayDate(key, {month: 'long', day: 'numeric'});
        q('[data-latest-time]').textContent = dateParts ? `${dateParts.hour}:${dateParts.minute}` : '';
        q('[data-latest-meta]').textContent = displayDate(key, {year: 'numeric', weekday: 'long'}) + ` · ${tr('北京时间')}`;

        const post = Array.isArray(event.posts) ? event.posts[0] : null;
        const postBox = q('[data-latest-post]');
        if (!post) {
            postBox.hidden = true;
            return;
        }
        postBox.hidden = false;
        q('[data-latest-post-time]').textContent = displayDateTime(post.publishedAt, {
            month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
        });
        q('[data-latest-post-text]').textContent = sourceText(post) || eventTitle(event);
        setLink(q('[data-latest-post-link]'), post.url);
    }

    function renderRecent(events) {
        const list = q('[data-recent-list]');
        list.replaceChildren();
        events.slice(0, 4).forEach(event => {
            const key = eventDate(event);
            const button = node('button', 'ln-codex-recent-item');
            button.type = 'button';
            const when = node('span');
            when.append(node('strong', '', displayDate(key, {month: 'long', day: 'numeric'})), node('small', '', tr('北京时间')));
            const type = node('b', event.type === 'reset_credit' ? 'is-credit' : 'is-reset', kindLabel(event));
            const arrow = node('i', '', '›');
            button.append(when, type, arrow);
            button.addEventListener('click', () => {
                selectedDate = key;
                const [year, month] = key.split('-').map(Number);
                calendarMonth = {year, month};
                renderCalendar();
                q('[data-selected-date]').scrollIntoView({behavior: 'smooth', block: 'center'});
            });
            list.append(button);
        });
    }

    function monthShift(delta) {
        const date = new Date(Date.UTC(calendarMonth.year, calendarMonth.month - 1 + delta, 1));
        calendarMonth = {year: date.getUTCFullYear(), month: date.getUTCMonth() + 1};
        renderCalendar();
    }

    function renderCalendar() {
        const events = snapshot.events;
        const groups = groupEvents(events);
        const monthKey = `${calendarMonth.year}-${String(calendarMonth.month).padStart(2, '0')}`;
        q('[data-calendar-title]').textContent = displayDate(`${monthKey}-01`, {year: 'numeric', month: 'long'});

        const weekdays = q('[data-calendar-weekdays]');
        weekdays.replaceChildren();
        const monday = new Date(Date.UTC(2026, 8, 7, 12));
        for (let i = 0; i < 7; i++) {
            const day = new Date(monday.getTime() + i * 86400000);
            weekdays.append(node('span', '', new Intl.DateTimeFormat(locale, {weekday: 'short'}).format(day)));
        }

        const first = new Date(Date.UTC(calendarMonth.year, calendarMonth.month - 1, 1));
        const offset = (first.getUTCDay() + 6) % 7;
        const start = new Date(Date.UTC(calendarMonth.year, calendarMonth.month - 1, 1 - offset));
        const today = dateKey(new Date().toISOString());
        const days = q('[data-calendar-days]');
        days.replaceChildren();

        for (let index = 0; index < 42; index++) {
            const date = new Date(start.getTime() + index * 86400000);
            const key = `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
            const dayEvents = groups.get(key) || [];
            const hasReset = dayEvents.some(item => item.type === 'direct_reset');
            const hasCredit = dayEvents.some(item => item.type === 'reset_credit');
            const button = node('button', 'ln-codex-day');
            button.type = 'button';
            button.classList.toggle('is-other', date.getUTCMonth() + 1 !== calendarMonth.month);
            button.classList.toggle('is-selected', key === selectedDate);
            button.classList.toggle('is-today', key === today);
            button.classList.toggle('has-events', dayEvents.length > 0);
            button.setAttribute('aria-label', `${displayDate(key, {year: 'numeric', month: 'long', day: 'numeric'})}${dayEvents.length ? `，${dayEvents.length} ${tr('条记录')}` : ''}`);
            button.append(node('strong', '', date.getUTCDate()));
            const marks = node('span', 'ln-codex-day__marks');
            if (hasReset) marks.append(node('i', 'is-reset'));
            if (hasCredit) marks.append(node('i', 'is-credit'));
            button.append(marks);
            button.addEventListener('click', () => {
                selectedDate = key;
                if (date.getUTCMonth() + 1 !== calendarMonth.month) {
                    calendarMonth = {year: date.getUTCFullYear(), month: date.getUTCMonth() + 1};
                }
                renderCalendar();
            });
            days.append(button);
        }

        renderSelected(groups.get(selectedDate) || []);
    }

    function renderSelected(events) {
        q('[data-selected-date]').textContent = displayDate(selectedDate, {year: 'numeric', month: 'long', day: 'numeric'});
        q('[data-selected-count]').textContent = events.length ? `${events.length} ${tr('条记录')}` : tr('当天没有记录');
        const list = q('[data-selected-events]');
        list.replaceChildren();
        if (!events.length) {
            list.append(node('p', 'ln-codex-day-empty', tr('选择有标记的日期查看公开记录。')));
            return;
        }
        events.forEach(event => {
            const article = node('article', 'ln-codex-day-event');
            const top = node('div');
            top.append(
                node('span', event.type === 'reset_credit' ? 'is-credit' : 'is-reset', kindLabel(event)),
                node('small', '', statusLabel(event))
            );
            article.append(top, node('h3', '', eventTitle(event)));
            const post = Array.isArray(event.posts) ? event.posts[0] : null;
            const quote = sourceText(post);
            if (quote) article.append(node('p', '', quote));
            if (post?.url || event.url) {
                const link = node('a', '', tr('查看原帖') + ' ↗');
                link.target = '_blank';
                link.rel = 'noopener noreferrer nofollow';
                setLink(link, post?.url || event.url);
                article.append(link);
            }
            list.append(article);
        });
    }

    function render(data) {
        snapshot = data;
        const events = Array.isArray(data.events)
            ? data.events.filter(event => event && event.id && eventDate(event)).sort((left, right) => eventSortValue(right) - eventSortValue(left))
            : [];
        if (!events.length) {
            showState(tr('暂时没有重置记录'), tr('数据源尚未返回可展示的公开记录。'), true);
            return;
        }
        snapshot.events = events;
        const latest = events[0];
        selectedDate = eventDate(latest);
        const [year, month] = selectedDate.split('-').map(Number);
        calendarMonth = {year, month};
        renderLatest(latest);
        renderRecent(events);
        renderCalendar();
        const checkedText = data.checkedAt
            ? `${data.stale ? tr('正在显示上次成功数据') : tr('数据已核对')} · ${displayDateTime(data.checkedAt, {year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'})}（${tr('北京时间')}）`
            : tr('数据核对时间暂不可用');
        q('[data-checked-at]').textContent = checkedText;
        setLink(q('[data-source-link]'), data.source?.url || 'https://aihot.news/codex-reset');
        stateNode.hidden = true;
        contentNode.hidden = false;
    }

    async function load() {
        stateNode.hidden = false;
        contentNode.hidden = true;
        try {
            const response = await fetch('/user/api/index/codexResets', {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'}
            });
            const payload = await response.json();
            if (!response.ok || payload?.code !== 200 || !payload.data?.enabled) {
                throw new Error('feed disabled');
            }
            if (!payload.data.available) {
                showState(tr('重置记录暂不可用'), tr('上游数据暂时无法读取，请稍后再试。'), true);
                return;
            }
            render(payload.data);
        } catch (error) {
            showState(tr('重置记录暂不可用'), tr('上游数据暂时无法读取，请稍后再试。'), true);
        }
    }

    q('[data-calendar-prev]').addEventListener('click', () => monthShift(-1));
    q('[data-calendar-next]').addEventListener('click', () => monthShift(1));
    q('[data-calendar-today]').addEventListener('click', () => {
        const latestKey = eventDate(snapshot.events[0]);
        selectedDate = latestKey;
        const [year, month] = latestKey.split('-').map(Number);
        calendarMonth = {year, month};
        renderCalendar();
    });

    load();
}());
