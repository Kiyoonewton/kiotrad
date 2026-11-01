import copy
import json
import subprocess
import unittest
from unittest.mock import patch

from project_api import GitHub
from setup_project import CARDS, setup


class FakeGitHub:
    def __init__(self):
        self.login = 'Kiyoonewton'
        self.project = None
        self.field_rows = [{'id': 'status', 'name': 'Status', 'options': [
            {'id': 'todo', 'name': 'Todo', 'color': 'GRAY', 'description': ''},
            {'id': 'progress', 'name': 'In progress', 'color': 'YELLOW', 'description': ''},
            {'id': 'done', 'name': 'Done', 'color': 'GREEN', 'description': ''}]}]
        self.item_rows, self.views, self.writes = {}, [], []
        self.closed = set()
        self.page_two = False

    def __call__(self, query, variables=None):
        variables = variables or {}
        if query.startswith('query{viewer'):
            return {'viewer': {'id': 'owner', 'login': self.login}, 'repository': {'id': 'repo', **{
                'i' + str(n): {'id': 'issue' + str(n), 'number': n, 'state': 'CLOSED' if n in self.closed else 'OPEN',
                              'url': 'https://github.com/Kiyoonewton/kiotrad/issues/' + str(n)} for n in CARDS}}}
        if 'projectsV2(first:' in query:
            return {'viewer': {'projectsV2': {'nodes': [self.project] if self.project else [], 'pageInfo': {'hasNextPage': False, 'endCursor': None}}}}
        if 'fields(first:' in query:
            return {'node': {'fields': {'nodes': copy.deepcopy(self.field_rows), 'pageInfo': {'hasNextPage': False}}}}
        if 'items(first:' in query:
            return {'node': {'items': {'nodes': copy.deepcopy(list(self.item_rows.values())), 'pageInfo': {'hasNextPage': False, 'endCursor': None}}}}
        if 'views(first:' in query:
            return {'node': {'views': {'nodes': self.views, 'pageInfo': {'hasNextPage': False}}}}
        self.writes.append((query, copy.deepcopy(variables)))
        values = variables['input']
        if 'createProjectV2(input:' in query:
            self.project = {'id': 'project', 'number': 1, 'title': 'KioTrad', 'url': 'https://github.com/users/Kiyoonewton/projects/1', 'closed': False}
            return {'createProjectV2': {'projectV2': self.project}}
        if 'updateProjectV2(input:' in query:
            return {'updateProjectV2': {'projectV2': self.project}}
        if 'linkProjectV2ToRepository(input:' in query:
            return {'linkProjectV2ToRepository': {'repository': {'id': 'repo'}}}
        if 'ProjectV2Field(input:' in query:
            name = 'createProjectV2Field' if 'createProjectV2Field' in query else 'updateProjectV2Field'
            if name.startswith('create'):
                field = {'id': values['name'], 'name': values['name']}
                self.field_rows.append(field)
            else:
                field = next(f for f in self.field_rows if f['id'] == values['fieldId'])
            field['options'] = [{**o, 'id': o.get('id', field['id'] + str(i))} for i, o in enumerate(values['singleSelectOptions'])]
            return {name: {'projectV2Field': copy.deepcopy(field)}}
        if 'addProjectV2ItemById' in query:
            row = {'id': values['contentId'] + '-item', 'content': {'id': values['contentId']}, 'fieldValues': {'nodes': [], 'pageInfo': {'hasNextPage': False}}}
            self.item_rows[row['id']] = row
            return {'addProjectV2ItemById': {'item': copy.deepcopy(row)}}
        if 'updateProjectV2ItemFieldValue' in query:
            row = self.item_rows[values['itemId']]
            row['fieldValues']['nodes'].append({'field': {'id': values['fieldId']}, 'optionId': values['value']['singleSelectOptionId']})
            return {'updateProjectV2ItemFieldValue': {'projectV2Item': {'id': row['id']}}}
        if 'createProjectV2View' in query:
            self.views.append({'id': 'board', 'name': values['name'], 'layout': values['layout']})
            return {'createProjectV2View': {'projectV2View': {'id': 'board'}}}
        raise AssertionError(query)


class ProjectSetupTest(unittest.TestCase):
    def test_setup_is_resumable_without_duplicate_projects_items_or_status_resets(self):
        api = FakeGitHub()
        first = setup(api, progress=lambda _: None)
        self.assertEqual(len(first['items']), 3)
        self.assertEqual(len(api.item_rows), 3)
        status = next(f for f in api.field_rows if f['name'] == 'Status')
        self.assertEqual(status['options'][0]['id'], 'todo')
        self.assertEqual(status['options'][0]['name'], 'Backlog')
        self.assertEqual(api.views[0]['layout'], 'BOARD_LAYOUT')
        api.item_rows['issue92-item']['fieldValues']['nodes'][0]['optionId'] = 'done'
        writes = len(api.writes)
        setup(api, progress=lambda _: None)
        self.assertEqual(len(api.item_rows), 3)
        self.assertEqual(api.item_rows['issue92-item']['fieldValues']['nodes'][0]['optionId'], 'done')
        self.assertEqual(len(api.writes) - writes, 1)  # Repository association only.

    def test_closed_tasks_start_done_and_wrong_account_cannot_write(self):
        api = FakeGitHub()
        api.closed.add(92)
        setup(api, progress=lambda _: None)
        self.assertEqual(api.item_rows['issue92-item']['fieldValues']['nodes'][0]['optionId'], 'done')
        wrong = FakeGitHub()
        wrong.login = 'someone-else'
        with self.assertRaises(ValueError):
            setup(wrong, progress=lambda _: None)
        self.assertFalse(wrong.writes)

    def test_existing_custom_options_are_preserved_with_their_ids(self):
        api = FakeGitHub()
        setup(api, progress=lambda _: None)
        api.field_rows[0]['options'].append({'id': 'blocked', 'name': 'Blocked', 'color': 'RED', 'description': 'Keep'})
        setup(api, progress=lambda _: None)
        self.assertIn({'id': 'blocked', 'name': 'Blocked', 'color': 'RED', 'description': 'Keep'}, api.field_rows[0]['options'])
        with self.assertRaises(ValueError):
            setup(api, project_number=999, progress=lambda _: None)

    def test_project_lookup_checks_later_pages_before_creating_anything(self):
        api = FakeGitHub()
        setup(api, progress=lambda _: None)
        cursors = []
        def paginated(query, variables=None):
            if 'projectsV2(first:' in query:
                cursors.append(variables['after'])
                if variables['after'] is None:
                    return {'viewer': {'projectsV2': {'nodes': [], 'pageInfo': {'hasNextPage': True, 'endCursor': 'second'}}}}
            return api(query, variables)
        writes = len(api.writes)
        setup(paginated, progress=lambda _: None)
        self.assertEqual(cursors, [None, 'second'])
        self.assertEqual(len(api.writes) - writes, 1)

    def test_cli_errors_do_not_expose_stderr_tokens_or_command_arguments(self):
        with patch('project_api.subprocess.run', return_value=subprocess.CompletedProcess([], 1, b'', b'secret-token')) as run:
            with self.assertRaises(RuntimeError) as failure:
                GitHub()('query{viewer{id}}')
            self.assertNotIn('secret-token', str(failure.exception))
            self.assertEqual(run.call_args.args[0], ['gh', 'api', '--hostname', 'github.com', 'graphql', '--input', '-'])
            self.assertEqual(json.loads(run.call_args.kwargs['input'])['query'], 'query{viewer{id}}')
