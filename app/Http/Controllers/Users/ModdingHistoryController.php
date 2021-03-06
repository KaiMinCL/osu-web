<?php

/**
 *    Copyright 2015-2018 ppy Pty. Ltd.
 *
 *    This file is part of osu!web. osu!web is distributed with the hope of
 *    attracting more community contributions to the core ecosystem of osu!.
 *
 *    osu!web is free software: you can redistribute it and/or modify
 *    it under the terms of the Affero GNU General Public License version 3
 *    as published by the Free Software Foundation.
 *
 *    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
 *    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *    See the GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\BeatmapDiscussion;
use App\Models\BeatmapDiscussionPost;
use App\Models\BeatmapDiscussionVote;
use App\Models\BeatmapsetEvent;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class ModdingHistoryController extends Controller
{
    protected $actionPrefix = 'modding-history-';
    protected $section = 'user';

    protected $isModerator;
    protected $searchParams;
    protected $user;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->isModerator = priv_check('BeatmapDiscussionModerate')->can();
            $this->user = User::lookup(request('user'), 'id', $this->isModerator);

            if ($this->user === null || $this->user->isBot() || !priv_check('UserShow', $this->user)->can()) {
                abort(404);
            }

            $this->searchParams = request();
            $this->searchParams['is_moderator'] = $this->isModerator;

            if (!$this->isModerator) {
                $this->searchParams['with_deleted'] = false;
            }

            return $next($request);
        });

        parent::__construct();
    }

    public function index()
    {
        $user = $this->user;

        $this->searchParams['limit'] = 10;
        $this->searchParams['sort'] = 'id-desc';
        $this->searchParams['with_deleted'] = $this->isModerator;

        $discussions = BeatmapDiscussion::search($this->searchParams);
        $discussions['items'] = $discussions['query']->with([
                'user',
                'beatmapset',
                'startingPost',
            ])->get();

        $posts = BeatmapDiscussionPost::search($this->searchParams);
        $posts['items'] = $posts['query']->with([
                'user',
                'beatmapset',
                'beatmapDiscussion',
                'beatmapDiscussion.beatmapset',
                'beatmapDiscussion.user',
                'beatmapDiscussion.startingPost',
            ])->get();

        $events = BeatmapsetEvent::search($this->searchParams);
        if ($this->isModerator) {
            $events['items'] = $events['query']->with('user')->with(['beatmapset' => function ($query) {
                $query->withTrashed();
            }])->get();
        } else {
            $events['items'] = $events['query']->with(['user', 'beatmapset'])->get();
        }

        $votes['items'] = BeatmapDiscussionVote::recentlyGivenByUser($user->getKey());
        $receivedVotes['items'] = BeatmapDiscussionVote::recentlyReceivedByUser($user->getKey());

        return view('users.beatmapset_activities', compact(
            'current_action',
            'discussions',
            'events',
            'posts',
            'user',
            'receivedVotes',
            'votes'
        ));
    }

    public function discussions()
    {
        $user = $this->user;

        $search = BeatmapDiscussion::search($this->searchParams);
        $discussions = new LengthAwarePaginator(
            $search['query']->with([
                    'user',
                    'beatmapset',
                    'startingPost',
                ])->get(),
            $search['query']->realCount(),
            $search['params']['limit'],
            $search['params']['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $search['params'],
            ]
        );

        return view('beatmap_discussions.index', compact('discussions', 'search', 'user'));
    }

    public function events()
    {
        $user = $this->user;

        $search = BeatmapsetEvent::search($this->searchParams);
        if ($this->isModerator) {
            $items = $search['query']->with('user')->with(['beatmapset' => function ($query) {
                $query->withTrashed();
            }])->get();
        } else {
            $items = $search['query']->with(['user', 'beatmapset'])->get();
        }

        $events = new LengthAwarePaginator(
            $items,
            $search['query']->realCount(),
            $search['params']['limit'],
            $search['params']['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $search['params'],
            ]
        );

        return view('beatmapset_events.index', compact('events', 'user'));
    }

    public function posts()
    {
        $user = $this->user;

        $search = BeatmapDiscussionPost::search($this->searchParams);
        $posts = new LengthAwarePaginator(
            $search['query']->with([
                    'user',
                    'beatmapset',
                    'beatmapDiscussion',
                    'beatmapDiscussion.beatmapset',
                    'beatmapDiscussion.user',
                    'beatmapDiscussion.startingPost',
                ])->get(),
            $search['query']->realCount(),
            $search['params']['limit'],
            $search['params']['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $search['params'],
            ]
        );

        return view('beatmap_discussion_posts.index', compact('posts', 'user'));
    }

    public function votesGiven()
    {
        $user = $this->user;

        $search = BeatmapDiscussionVote::search($this->searchParams);
        $votes = new LengthAwarePaginator(
            $search['query']->with([
                    'user',
                    'beatmapDiscussion',
                    'beatmapDiscussion.user',
                    'beatmapDiscussion.beatmapset',
                    'beatmapDiscussion.startingPost',
                ])->get(),
            $search['query']->realCount(),
            $search['params']['limit'],
            $search['params']['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $search['params'],
            ]
        );

        return view('beatmapset_discussion_votes.index', compact('votes', 'user'));
    }

    public function votesReceived()
    {
        $user = $this->user;
        // quick workaround for existing call
        $this->searchParams['receiver'] = $user->getKey();
        unset($this->searchParams['user']);

        $search = BeatmapDiscussionVote::search($this->searchParams);
        $votes = new LengthAwarePaginator(
            $search['query']->with([
                    'user',
                    'beatmapDiscussion',
                    'beatmapDiscussion.user',
                    'beatmapDiscussion.beatmapset',
                    'beatmapDiscussion.startingPost',
                ])->get(),
            $search['query']->realCount(),
            $search['params']['limit'],
            $search['params']['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $search['params'],
            ]
        );

        return view('beatmapset_discussion_votes.index', compact('votes', 'user'));
    }
}
