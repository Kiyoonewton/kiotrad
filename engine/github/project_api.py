"""GitHub Projects GraphQL requests through the user's existing gh login."""
import json
import subprocess


class GitHub:
    def __call__(self, query, variables=None):
        try:
            result = subprocess.run(['gh', 'api', '--hostname', 'github.com', 'graphql', '--input', '-'],
                                    input=json.dumps({'query': query, 'variables': variables or {}}).encode(),
                                    stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=90)
        except FileNotFoundError:
            raise RuntimeError('Install GitHub CLI first: brew install gh') from None
        except (OSError, subprocess.TimeoutExpired):
            raise RuntimeError('GitHub request failed or timed out; rerun to resume. Credentials hidden.') from None
        if result.returncode:
            raise RuntimeError('GitHub access failed. Check gh auth login and gh auth refresh -h github.com -s project. Credentials hidden.')
        try:
            body = json.loads(result.stdout)
            if body.get('errors') or not isinstance(body.get('data'), dict):
                raise ValueError()
            return body['data']
        except (ValueError, AttributeError):
            raise RuntimeError('GitHub returned an unsuccessful response; rerun to resume. Credentials hidden.') from None


def mutation(api, name, input_type, values, result):
    return api(f'mutation($input:{input_type}!){{{name}(input:$input){{{result}}}}}', {'input': values})[name]


def projects(api):
    rows, cursor = [], None
    while True:
        connection = api('query($after:String){viewer{projectsV2(first:100,after:$after){nodes{id number title url closed} pageInfo{hasNextPage endCursor}}}}',
                         {'after': cursor})['viewer']['projectsV2']
        rows.extend(connection['nodes'])
        if not connection['pageInfo']['hasNextPage']:
            return rows
        cursor = connection['pageInfo']['endCursor']


def fields(api, project):
    connection = api('query($id:ID!){node(id:$id){...on ProjectV2{fields(first:100){nodes{...on ProjectV2Field{id name} ...on ProjectV2IterationField{id name} ...on ProjectV2SingleSelectField{id name options{id name color description}}} pageInfo{hasNextPage}}}}}',
                     {'id': project})['node']['fields']
    if connection['pageInfo']['hasNextPage']:
        raise ValueError('Project has more than 100 fields; review manually before setup.')
    return connection['nodes']


def items(api, project):
    rows, cursor = [], None
    while True:
        connection = api('query($id:ID!,$after:String){node(id:$id){...on ProjectV2{items(first:100,after:$after){nodes{id content{...on Issue{id}} fieldValues(first:100){nodes{...on ProjectV2ItemFieldSingleSelectValue{optionId field{...on ProjectV2SingleSelectField{id}}}} pageInfo{hasNextPage}}} pageInfo{hasNextPage endCursor}}}}}',
                         {'id': project, 'after': cursor})['node']['items']
        if any(row['fieldValues']['pageInfo']['hasNextPage'] for row in connection['nodes']):
            raise ValueError('Item has more than 100 field values; review manually.')
        rows.extend(connection['nodes'])
        if not connection['pageInfo']['hasNextPage']:
            return rows
        cursor = connection['pageInfo']['endCursor']


def board_view(api, project):
    connection = api('query($id:ID!){node(id:$id){...on ProjectV2{views(first:100){nodes{id name layout} pageInfo{hasNextPage}}}}}',
                     {'id': project})['node']['views']
    if connection['pageInfo']['hasNextPage']:
        raise ValueError('Project has more than 100 views; review manually.')
    matches = [v for v in connection['nodes'] if v['name'] == 'Delivery board']
    if len(matches) > 1 or (matches and matches[0]['layout'] != 'BOARD_LAYOUT'):
        raise ValueError('Delivery board view is ambiguous or has a different layout; existing views preserved.')
    if not matches:
        mutation(api, 'createProjectV2View', 'CreateProjectV2ViewInput',
                 {'projectId': project, 'name': 'Delivery board', 'layout': 'BOARD_LAYOUT'}, 'projectV2View{id}')
