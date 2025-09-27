<?php

namespace App\Repositories;

use App\Models\Memo;
use App\Support\Database;
use PDO;

class MemoRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * @return Memo[]
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM memos WHERE archived = 0 ORDER BY pinned DESC, updated_at DESC');
        $rows = $stmt->fetchAll();
        return array_map([$this, 'map'], $rows);
    }

    public function find(int $id): ?Memo
    {
        $stmt = $this->pdo->prepare('SELECT * FROM memos WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->map($row) : null;
    }

    public function create(array $attributes): Memo
    {
        $now = time();
        $stmt = $this->pdo->prepare('INSERT INTO memos (title, content_md, content_html, is_done, pinned, archived, updated_at, created_at) VALUES (:title, :content_md, :content_html, :is_done, :pinned, :archived, :updated_at, :created_at)');
        $stmt->execute([
            'title' => $attributes['title'],
            'content_md' => $attributes['content_md'] ?? null,
            'content_html' => $attributes['content_html'] ?? null,
            'is_done' => $attributes['is_done'] ?? 0,
            'pinned' => $attributes['pinned'] ?? 0,
            'archived' => $attributes['archived'] ?? 0,
            'updated_at' => $now,
            'created_at' => $now,
        ]);

        $id = (int)$this->pdo->lastInsertId();
        return $this->find($id);
    }

    public function update(int $id, array $attributes): ?Memo
    {
        $memo = $this->find($id);
        if (!$memo) {
            return null;
        }

        $fields = [];
        $bindings = ['id' => $id];
        foreach (['title', 'content_md', 'content_html', 'is_done', 'pinned', 'archived', 'done_at'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fields[] = $field . ' = :' . $field;
                $bindings[$field] = $attributes[$field];
            }
        }
        $fields[] = 'updated_at = :updated_at';
        $bindings['updated_at'] = time();

        $sql = 'UPDATE memos SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $this->find($id);
    }

    public function toggleDone(int $id): ?Memo
    {
        $memo = $this->find($id);
        if (!$memo) {
            return null;
        }

        $isDone = !$memo->isDone;
        $doneAt = $isDone ? time() : null;

        return $this->update($id, [
            'is_done' => $isDone ? 1 : 0,
            'done_at' => $doneAt,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Memo
    {
        return new Memo(
            id: (int)$row['id'],
            title: (string)$row['title'],
            contentMd: $row['content_md'],
            contentHtml: $row['content_html'],
            isDone: (bool)$row['is_done'],
            doneAt: $row['done_at'] ? (int)$row['done_at'] : null,
            pinned: (bool)$row['pinned'],
            archived: (bool)$row['archived'],
            updatedAt: (int)$row['updated_at'],
            createdAt: (int)$row['created_at'],
        );
    }
}

